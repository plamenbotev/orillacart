<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class TaxRateNotFound extends NotFound
{
    public function __construct(public readonly int $rateId)
    {
        parent::__construct('The tax group has no rate %d.', 'orillacart_tax_rate_not_found', values: [$rateId]);
    }
}
