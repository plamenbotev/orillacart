<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Tax;

/**
 * Money given back on an order, and the tax inside it.
 */
final readonly class TaxRefund
{
    /**
     * @param string $amount what was given back, tax included, decimal
     * @param string $tax    the tax in it
     * @param string $date   the day, Y-m-d
     * @param bool   $full   the order was refunded in full
     */
    public function __construct(
        public string $amount,
        public string $tax,
        public string $date,
        public bool $full,
    ) {}
}
