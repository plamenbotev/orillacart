<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * What a refund gave back, as the books need it: the tax inside the amount, by the order's tax lines, and the
 * stock to put back. A refund's tax is the share of the amount that the tax is of what it covers: the refunded
 * items and shipping, or the whole order for an amount alone.
 */
final readonly class RefundShare
{
    /**
     * @param list<TaxLine>    $taxes the tax given back at each of the order's rates
     * @param list<TakenItem>  $taken the stock to put back
     */
    public function __construct(
        public Money $tax,
        public array $taxes,
        public array $taken,
    ) {}
}
