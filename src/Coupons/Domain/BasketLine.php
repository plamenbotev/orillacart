<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * An item a coupon may take money off: what it is, how many, and what they cost before tax.
 */
final readonly class BasketLine
{
    /**
     * @param list<int> $categoryIds with every category above them
     * @param list<int> $brandIds    with every brand above them
     */
    public function __construct(
        public int $productId,
        public int $quantity,
        public Money $total,
        public bool $onSale = false,
        public array $categoryIds = [],
        public array $brandIds = [],
        /** Coupons may come off it: not when a price rule that set its price says so. */
        public bool $coupons = true,
    ) {}
}
