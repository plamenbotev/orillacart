<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * Query: what a product costs with these options, and how many can be sold.
 */
final readonly class QuoteRequest
{
    /**
     * @param list<int> $optionIds  the customer's choices; for a product with variations, exactly a variation's options
     * @param int       $quantity   how many are bought, for prices by quantity
     * @param int|null  $customerId who buys, for the prices of their customer groups
     */
    public function __construct(
        public int $productId,
        public array $optionIds = [],
        public int $quantity = 1,
        public ?int $customerId = null,
    ) {}
}
