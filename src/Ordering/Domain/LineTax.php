<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\TaxJurisdiction;

/**
 * One rate on one line of an order, as it was charged: who it is owed to, what it was charged on before tax, and
 * how much.
 */
final readonly class LineTax
{
    public function __construct(
        public string $name,
        public string $percent,
        public TaxJurisdiction $jurisdiction,
        public Money $taxable,
        public Money $amount,
        public bool $compound = false,
    ) {}
}
