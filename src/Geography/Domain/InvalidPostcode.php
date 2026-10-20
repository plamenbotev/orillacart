<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

final class InvalidPostcode extends RuleViolation
{
    public function __construct(public readonly string $countryName, public readonly string $example)
    {
        parent::__construct(\sprintf('That is not a postcode of %s, which look like %s.', $countryName, $example), 'orillacart_invalid_postcode');
    }
}
