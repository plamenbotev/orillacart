<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Infrastructure\WordPress;

use OrillaCart\Exchange\Application\ColumnNames;

/**
 * The shop's own export columns' names, translated: each written out here for the translation tools to find.
 */
final class TranslatedColumnNames implements ColumnNames
{
    /** @var array<string, string>|null */
    private ?array $names = null;

    public function of(string $id, string $english): string
    {
        $this->names ??= self::names();

        return $this->names[$id] ?? $english;
    }

    /** @return array<string, string> */
    private static function names(): array
    {
        return [
            'number' => __('Order', 'orillacart'),
            'status' => __('Status', 'orillacart'),
            'placed_at' => __('Placed (UTC)', 'orillacart'),
            'paid_at' => __('Paid (UTC)', 'orillacart'),
            'email' => __('Email', 'orillacart'),
            'customer_id' => __('Customer', 'orillacart'),
            'billing_name' => __('Billing name', 'orillacart'),
            'billing_street' => __('Billing street', 'orillacart'),
            'billing_city' => __('Billing city', 'orillacart'),
            'billing_postcode' => __('Billing postcode', 'orillacart'),
            'billing_country' => __('Billing country', 'orillacart'),
            'billing_phone' => __('Phone', 'orillacart'),
            'shipping_name' => __('Shipping name', 'orillacart'),
            'shipping_address' => __('Shipping address', 'orillacart'),
            'items' => __('Items', 'orillacart'),
            'item_count' => __('Item count', 'orillacart'),
            'subtotal' => __('Subtotal', 'orillacart'),
            'discount' => __('Discount', 'orillacart'),
            'shipping_total' => __('Shipping', 'orillacart'),
            'tax' => __('Tax', 'orillacart'),
            'total' => __('Total', 'orillacart'),
            'refunded' => __('Refunded', 'orillacart'),
            'currency' => __('Currency', 'orillacart'),
            'payment' => __('Payment', 'orillacart'),
            'transaction_id' => __('Transaction', 'orillacart'),
            'shipping' => __('Shipping method', 'orillacart'),
            'note' => __('Customer note', 'orillacart'),
        ];
    }
}
