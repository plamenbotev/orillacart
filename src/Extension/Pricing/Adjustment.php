<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Pricing;

/**
 * A change to an order's prices, for one of its lines or the whole of it.
 */
final readonly class Adjustment
{
    /**
     * @param string   $amount decimal, below zero to lower the price: "-5.00"; it takes no more than what is left
     * @param int|null $line   the line's index in the query; null for the whole order, spread over its lines
     */
    public function __construct(
        public string $label,
        public string $amount,
        public ?int $line = null,
        public string $reason = '',
    ) {}
}
