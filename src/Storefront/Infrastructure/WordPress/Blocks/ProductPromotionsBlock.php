<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Coupons\Application\Dto\DiscountItem;
use OrillaCart\Coupons\Application\Dto\PromotionView;
use OrillaCart\Coupons\Application\Promotions;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;
use OrillaCart\Storefront\Infrastructure\WordPress\PromotionWords;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * The promotions shown to shoppers that take something off this product, in words: "Buy 2, get 1 free", "10% off
 * on orders of €50.00 or more", a free gift with a link to it, and the code to use for one with a code. Nothing for
 * a product not for sale now, or without any.
 *
 * A visitor's page may come from a page cache, so it shows only what holds whoever shops, for the shop's country.
 * A logged-in customer's, which caches do not keep, adds those for them alone (a first order, uses left) and goes
 * by their saved address's country.
 */
final readonly class ProductPromotionsBlock implements BlockDefinition
{
    public function __construct(
        private ProductParts $parts,
        private Promotions $promotions,
        private ProductQuotes $quotes,
        private ProductContent $content,
        private ProductTranslations $translations,
        private Shopper $shopper,
        private CustomerService $customers,
        private SettingsService $settings,
        private Currencies $currencies,
        private PromotionWords $words,
    ) {}

    public static function name(): string
    {
        return 'orillacart/product-promotions';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);
        $offer = $id === null ? null : $this->parts->offer($id);
        if ($id === null || $offer === null || !$offer->forSale || $offer->priceHidden) {
            return '';
        }
        $customer = $this->shopper->customerId();
        $country = ($customer === null ? null : $this->customers->savedAddress($customer)?->country) ?? $this->settings->current()->shopCountry;
        $currency = $this->currencies->current();
        $product = $this->translations->original($id);
        try {
            // As a cart would see one of it: its categories and brands, a sale, a price rule keeping coupons off.
            $quote = $this->quotes->quote(new QuoteRequest($product, customerId: $customer));
            $item = new DiscountItem($product, 1, $this->currencies->price(Money::of($quote->unitPrice), $currency)->toFixed(), $quote->onSale, $quote->categoryIds, $quote->brandIds, $quote->coupons);
        } catch (NotFound|RuleViolation) {
            // A product whose variations are chosen first: priced from its lowest, as the page shows it.
            $terms = $this->content->termIds($product);
            $item = new DiscountItem($product, 1, $this->currencies->price(Money::of($offer->priceWithoutTax ?? $offer->price), $currency)->toFixed(), $offer->regularPrice !== null, $terms['categories'], $terms['brands']);
        }
        $promotions = $this->promotions->forProduct($item, $currency, $this->currencies->rate($currency), $country === '' ? null : $country, $customer);
        if (($attributes['showCodes'] ?? true) === false) {
            $promotions = array_values(array_filter($promotions, static fn(PromotionView $promotion): bool => $promotion->automatic));
        }

        return $this->words->product($id, $promotions);
    }
}
