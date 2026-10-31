<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Coupons\Application\Dto\PromotionView;
use OrillaCart\Coupons\Application\Promotions;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\TaxAddress;

/**
 * The promotions the shopper's cart gets and those it could still get, priced as the cart page prices it: at
 * their saved address or the shop's, in their currency, with the codes in the cart. Besides the coupons, the
 * lower price each of a product in the cart costs from more of it, by the shop's price rules for this shopper.
 * None for an empty cart, or one the shop refuses as it is.
 *
 * @phpstan-import-type Priced from OrderPricing
 */
final readonly class PromotionProgress
{
    public function __construct(
        private CheckoutService $checkout,
        private CartService $carts,
        private OrderPricing $pricing,
        private Promotions $promotions,
        private Currencies $currencies,
        private ProductQuotes $quotes,
        private ShownPrices $shownPrices,
    ) {}

    /**
     * Those it gets first, then those it could still get, the nearest first.
     *
     * @return list<PromotionView>
     */
    public function of(CartOwner $owner): array
    {
        $contents = $this->carts->contents($owner);
        if ($contents === null || $contents->refusals !== []) {
            return [];
        }
        $at = $this->checkout->estimateAt($owner);
        $address = $at === null ? null : new TaxAddress($at['country'], $at['state'], $at['postcode'], $at['city']);
        $currency = $this->currencies->current();
        try {
            $priced = $this->pricing->price($contents->lines, $address, $address, null, $at['postcode'] ?? null, $contents->coupons, $owner->customerId, currency: $currency);
        } catch (RuleViolation) {
            // An item no longer sold, or not in that quantity: the cart page says why.
            return [];
        }
        $coupons = $this->promotions->forCart($this->pricing->discountRequest($priced, $address?->country, $owner->customerId, $currency), $priced['discounts'], $contents->declined);
        $gets = array_values(array_filter($coupons, static fn(PromotionView $promotion): bool => $promotion->applied));
        $offers = [...array_values(array_filter($coupons, static fn(PromotionView $promotion): bool => !$promotion->applied)), ...$this->tiers($contents->lines, $priced, $owner->customerId, $currency)];
        // Stable: of two as near, the coupon first, as it was.
        usort($offers, static fn(PromotionView $a, PromotionView $b): int => $b->nearness <=> $a->nearness);

        return [...$gets, ...$offers];
    }

    /**
     * For each product in the cart, the next quantity from which each costs less, as the cart would then show its
     * price: one for the product, however many lines of it (prices by quantity count them together). Only a
     * quantity the shop can sell.
     *
     * @param list<array{product_id: int, option_ids: list<int>, bundle_choices: list<int>, quantity: int, extra: string, ...}> $items the cart's lines, which the priced lines begin with
     * @param Priced $priced
     *
     * @return list<PromotionView>
     */
    private function tiers(array $items, array $priced, ?int $customerId, string $currency): array
    {
        // Counted as checkout counts them: every line of the product, those given too.
        $counted = [];
        foreach ($priced['lines'] as $line) {
            $counted[$line['quote']->productId] = ($counted[$line['quote']->productId] ?? 0) + $line['quantity'];
        }
        $ofProduct = [];
        foreach ($items as $index => $item) {
            $line = $priced['lines'][$index] ?? null;
            if ($line !== null && $line['given'] === null) {
                $ofProduct[$line['quote']->productId][] = [$item, $line];
            }
        }
        $includesTax = $priced['bill']->includesTax();
        $withTax = $this->shownPrices->cartShowsTax();
        $decimals = $this->currencies->decimals($currency);
        $views = [];
        foreach ($ofProduct as $productId => $lines) {
            $quantity = $counted[$productId];
            foreach ($this->quotes->tierQuantities($lines[0][1]['quote'], $quantity, $customerId) as $next) {
                $prices = [];
                $sellable = false;
                foreach ($lines as [$item, $line]) {
                    $now = $line['quote'];
                    try {
                        $then = $this->quotes->quote(new QuoteRequest($item['product_id'], $item['option_ids'], $next, $customerId, $item['bundle_choices'], $item['extra']));
                    } catch (NotFound|RuleViolation) {
                        continue;
                    }
                    $sellable = $sellable || ($then->forSale && ($then->available === null || $then->available >= $line['quantity'] + $next - $quantity));
                    if (Money::of($then->unitPrice)->compare(Money::of($now->unitPrice)) < 0) {
                        $prices[] = (string) $this->shown($now, $then, $line, $currency, $includesTax, $withTax)->rounded($decimals);
                    }
                }
                if ($prices === []) {
                    // The price the rules give is no lower there (another rule's is lower already): the next one.
                    continue;
                }
                if ($sellable) {
                    $views[] = self::view($productId, $next, $quantity, \count(array_unique($prices)) === 1 && \count($prices) === \count($lines) ? $prices[0] : '', $currency);
                }
                break;
            }
        }

        return $views;
    }

    /**
     * What each of a line would cost at the quote for more, as the cart shows its price now: in the currency, as
     * this shopper pays it at their address, with tax or without as the cart shows it. Each of those changes it
     * in proportion, so the price shown now scales with the catalog's.
     *
     * @param array{unit_price: string, quantity: int, total: Money, discount: Money, tax: Money, ...} $line
     */
    private function shown(ItemQuote $now, ItemQuote $then, array $line, string $currency, bool $includesTax, bool $withTax): Money
    {
        $before = $this->currencies->price(Money::of($now->unitPrice), $currency);
        $after = $this->currencies->price(Money::of($then->unitPrice), $currency);
        $shownNow = Money::of(ShownPrices::line($line['unit_price'], $line['quantity'], (string) $line['total'], (string) $line['discount'], (string) $line['tax'], $includesTax, $withTax)[0]);

        return $before->isZero() || $shownNow->equals($before) ? $after : $after->proportion($shownNow, $before);
    }

    /**
     * @param string $price each, from that many on; empty when the product's lines would not all cost the same
     */
    private static function view(int $productId, int $next, int $quantity, string $price, string $currency): PromotionView
    {
        return new PromotionView(
            '',
            true,
            PromotionView::PRICE_TIER,
            '',
            $price,
            $currency,
            null,
            $next,
            0,
            0,
            [$productId],
            [],
            [],
            [],
            [],
            null,
            units: $quantity,
            neededUnits: $next,
            nearness: $quantity / $next,
        );
    }
}
