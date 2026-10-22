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
     * @param int       $quantity   how many of the product are bought, every line of it together: for prices by quantity
     * @param int|null  $customerId who buys, for the prices of their customer groups
     * @param list<int>|null $bundleChoices for a bundle, the variations the customer chose and the optional products
     *                                      added; null shows it before any choice: the first variation of each choice,
     *                                      without optional items
     * @param string         $extra         what the shopper's answers to the product's forms add to each item, in the
     *                                      shop's currency
     */
    public function __construct(
        public int $productId,
        public array $optionIds = [],
        public int $quantity = 1,
        public ?int $customerId = null,
        public ?array $bundleChoices = null,
        public string $extra = '0',
    ) {}
}
