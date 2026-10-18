<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

/**
 * The cart as the customer sees it: lines at current prices before tax, and the item count and subtotal.
 * Taxes and shipping depend on the address, so checkout adds them.
 */
final readonly class CartView
{
    /**
     * @param list<CartLineView> $lines
     * @param list<string>       $refusals why add-ons' cart rules refuse the cart as a whole, for the customer
     */
    public function __construct(
        public array $lines,
        public int $items,
        public string $subtotal,
        public bool $orderable,
        public array $refusals = [],
    ) {}

    public static function empty(): self
    {
        return new self([], 0, '0', false);
    }
}
