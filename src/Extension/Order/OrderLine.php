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
    ) {}
}
