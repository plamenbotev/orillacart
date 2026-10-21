<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Checkout\Application\Dto\CheckoutLineView;
use OrillaCart\Checkout\Application\Dto\CheckoutView;
use OrillaCart\Ordering\Application\Dto\OrderDiscountView;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Settings\Application\SettingsService;

/**
 * What the storefront tells analytics as a page shows: the product viewed, the cart checked out, the order
 * placed. Each is the event's data as Google Analytics 4 names it (currency, value, items), in a JSON script the
 * storefront script reads (assets/storefront/analytics.ts): it fires orillacart:<event> on the document, and
 * pushes it to the dataLayer when the shop says so.
 */
final readonly class AnalyticsEvents
{
    public function __construct(private SettingsService $settings) {}

    public function viewItem(int $productId, ProductOffer $offer): string
    {
        if ($offer->priceHidden) {
            return '';
        }
        $item = ['item_id' => $offer->sku ?? (string) $productId, 'item_name' => $offer->name, 'price' => (float) $offer->price, 'quantity' => 1];
        foreach (['item_brand' => ProductBrandTaxonomy::NAME, 'item_category' => ProductCategoryTaxonomy::NAME] as $key => $taxonomy) {
            $terms = get_the_terms($productId, $taxonomy);
            if (\is_array($terms) && $terms !== []) {
                $item[$key] = html_entity_decode($terms[0]->name, ENT_QUOTES);
            }
        }

        return self::script('view_item', ['currency' => $this->settings->current()->currency, 'value' => (float) $offer->price, 'items' => [$item]]);
    }

    public function beginCheckout(CheckoutView $summary): string
    {
        return self::script('begin_checkout', [
            'currency' => $summary->currency,
            'value' => (float) $summary->subtotal,
            'items' => array_map(static fn(CheckoutLineView $line): array => self::item($line->name, $line->unitPrice, $line->quantity), $summary->lines),
        ]);
    }

    public function purchase(OrderView $order): string
    {
        $coupons = implode(',', array_filter(array_map(static fn(OrderDiscountView $discount): string => $discount->automatic ? '' : $discount->code, $order->discounts), static fn(string $code): bool => $code !== ''));

        return self::script('purchase', array_filter([
            'transaction_id' => (string) $order->number,
            'currency' => $order->currency,
            'value' => (float) $order->total,
            'tax' => (float) $order->itemsTax + (float) $order->shippingTax,
            'shipping' => (float) $order->shippingTotal,
            'coupon' => $coupons,
            'items' => array_map(static fn(OrderLineView $line): array => ['item_id' => $line->sku ?? (string) $line->productId] + self::item($line->name, $line->unitPrice, $line->quantity), $order->lines),
        ], static fn(mixed $value): bool => $value !== ''));
    }

    /**
     * @return array{item_name: string, price: float, quantity: int}
     */
    private static function item(string $name, string $price, int $quantity): array
    {
        return ['item_name' => $name, 'price' => (float) $price, 'quantity' => $quantity];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function script(string $event, array $data): string
    {
        return '<script type="application/json" data-orillacart-event="' . esc_attr($event) . '">' . wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    }
}
