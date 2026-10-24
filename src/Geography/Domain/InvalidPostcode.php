<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

final class InvalidPostcode extends RuleViolation
{
    public function __construct(public readonly string $countryName, public readonly string $example)
    {
        parent::__construct('That is not a postcode of %1$s, which look like %2$s.', 'orillacart_invalid_postcode', values: [$countryName, $example]);
    }
}
