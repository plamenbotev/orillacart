<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * One item of an order, as it was sold: its name and price do not follow later catalog changes.
 */
final readonly class OrderLine
{
    /**
     * @param string $name      with the variation's or options' names: "T-shirt – Red / M"
     * @param string $unitPrice before tax and discounts
     * @param string $total     before tax, after the line's discount
     * @param list<array{label: string, answer: string, price: ?string}> $personalisation the answers to the shop's
     *                                                                   product forms, as sold (API 7): a file by its name
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public ?string $sku,
        public string $name,
        public int $quantity,
        public string $unitPrice,
        public string $total,
        public string $tax,
        public array $personalisation = [],
        /**
         * What the item is (API 18): "item" (sold), "given" (by a discount, at nothing after it), or "add_on" (kept in
         * the cart by an add-on's cart line type, whose id addOnType is).
         */
        public string $type = 'item',
        public ?string $addOnType = null,
    ) {}
}
