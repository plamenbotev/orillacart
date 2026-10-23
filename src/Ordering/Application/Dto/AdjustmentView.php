<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * A change to a line's price after its unit price: a coupon, an automatic discount, the shop's or an add-on's.
 * The amount is a decimal string, below zero when it lowered the price.
 */
final readonly class AdjustmentView
{
    public function __construct(
        /** "coupon", "automatic", "manual" or "add_on". */
        public string $kind,
        /** What made it: the coupon's code, the add-on's id, the shop manager's user id. */
        public string $source,
        public string $label,
        public string $amount,
        /** "order" for a share of one amount over the whole order, "line" for this line's own. */
        public string $scope = 'order',
        /** A coupon that made shipping free too. */
        public bool $freeShipping = false,
        public ?string $reason = null,
    ) {}
}
