<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * One tax rate charged on an order, over its items and its shipping, as the invoice lists it.
 */
final readonly class TaxLine
{
    public function __construct(
        public string $name,
        public string $percent,
        public Money $amount,
        /** Who it is owed to (TaxJurisdiction's code), where it is one place's: two rates of one name stay apart. */
        public string $code = '',
    ) {}
}
