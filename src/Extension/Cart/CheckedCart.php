<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Cart;

/**
 * A cart as cart rules see it, priced at the catalog's current prices.
 */
final readonly class CheckedCart
{
    /**
     * @param int|null          $customerId the WordPress user, null for a guest
     * @param list<CheckedLine> $lines
     */
    public function __construct(
        public ?int $customerId,
        public array $lines,
        public string $currency,
    ) {}
}
