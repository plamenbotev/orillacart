<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Cart;

/**
 * One line of a cart: a product, or one of its variations, with its options.
 */
final readonly class CheckedLine
{
    /**
     * @param int    $id        the line, to name in a CartRefusal
     * @param string $unitPrice before tax, with options: "12.50"
     */
    public function __construct(
        public int $id,
        public int $productId,
        public ?int $variationId,
        public ?string $sku,
        public string $name,
        public int $quantity,
        public string $unitPrice,
    ) {}
}
