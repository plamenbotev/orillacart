<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * Query: what a product costs with these options, and how many can be sold.
 */
final readonly class QuoteRequest
{
    /**
     * @param list<int> $optionIds the customer's choices; for a product with variations, exactly a variation's options
     */
    public function __construct(
        public int $productId,
        public array $optionIds = [],
    ) {}
}
