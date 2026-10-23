<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application\Dto;

/**
 * An item of an order being priced, as coupons see it.
 */
final readonly class DiscountItem
{
    /**
     * @param string    $total       before tax
     * @param list<int> $categoryIds with those above them
     * @param list<int> $brandIds    with those above them
     */
    public function __construct(
        public int $productId,
        public int $quantity,
        public string $total,
        public bool $onSale = false,
        public array $categoryIds = [],
        public array $brandIds = [],
        /** Codes and automatic discounts may come off it: not when a price rule that set its price says so. */
        public bool $coupons = true,
    ) {}
}
