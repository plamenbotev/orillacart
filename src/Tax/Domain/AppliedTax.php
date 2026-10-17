<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

/**
 * One tax rate charged on a sale, as invoices and the cart list it.
 */
final readonly class AppliedTax
{
    public function __construct(
        public ?int $rateId,
        public string $name,
        public Percentage $percent,
    ) {}
}
