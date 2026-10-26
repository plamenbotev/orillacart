<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Storefront;

/**
 * A product as the shopper is shown it now, for an add-on's ProductBlock. Since version 16.
 *
 * @param string      $price        the price shown, with or without tax as the shop shows prices: "35.00"
 * @param string|null $regularPrice the price before the sale, while on sale
 * @param int|null    $available    how many are in stock; null when the shop does not count them
 * @param string|null $rating       the average stars, "4.5"; null before the first review
 */
final readonly class ShownProduct
{
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public ?string $sku,
        public string $price,
        public ?string $regularPrice,
        public bool $forSale,
        public ?int $available,
        public ?string $rating,
        public int $reviews,
    ) {}
}
