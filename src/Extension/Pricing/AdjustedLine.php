<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Pricing;

/**
 * One item of an order being priced: what it is, how many, and what it costs after the coupons, before tax.
 */
final readonly class AdjustedLine
{
    /**
     * @param int    $index its place in the query, for Adjustment::$line
     * @param string $net   decimal: all of its quantity
     */
    public function __construct(
        public int $index,
        public int $productId,
        public ?int $variationId,
        public string $name,
        public int $quantity,
        public string $net,
    ) {}
}
