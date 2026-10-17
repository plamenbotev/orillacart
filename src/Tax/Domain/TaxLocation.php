<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

/**
 * Where tax is charged: a country, a state only when it belongs to that country, and whether the country
 * is in the EU. Built from addresses the Geography area has checked.
 */
final readonly class TaxLocation
{
    public function __construct(
        public string $country,
        public ?string $state = null,
        public bool $inEu = false,
    ) {}
}
