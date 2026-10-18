<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * An item as price rules see it: which product, in which categories and brands, and whether it is on sale.
 */
final readonly class PricedItem
{
    /**
     * @param list<int> $categoryIds with those above them
     * @param list<int> $brandIds    with those above them
     */
    public function __construct(
        public int $productId,
        public bool $onSale = false,
        public array $categoryIds = [],
        public array $brandIds = [],
    ) {}
}
