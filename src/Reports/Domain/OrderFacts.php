<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Domain;

/**
 * Everything the reports keep of one order: written together, replacing what was kept before.
 */
final readonly class OrderFacts
{
    /**
     * @param list<SoldLine>    $lines
     * @param list<UsedCoupon>  $coupons
     * @param list<GivenRefund> $refunds
     */
    public function __construct(
        public SoldOrder $order,
        public array $lines = [],
        public array $coupons = [],
        public array $refunds = [],
    ) {}
}
