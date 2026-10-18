<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Pricing;

/**
 * One item's price as the shop has it so far: the product's or variation's own price, before options and tax.
 */
final readonly class PriceQuery
{
    /**
     * @param int|null $variationId  null for the product itself, or when listings show the product's price
     * @param int      $quantity     how many the customer buys at once; 1 when only looking
     * @param int|null $customerId   the WordPress user, null for a guest and for the search index
     * @param string   $price        after the shop's price rules and earlier adjusters: "12.50"
     * @param string   $regularPrice the price before any sale or rule
     * @param string   $currency     ISO 4217: "EUR"
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public int $quantity,
        public ?int $customerId,
        public string $price,
        public string $regularPrice,
        public string $currency,
    ) {}
}
