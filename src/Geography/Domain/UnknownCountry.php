<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

final class UnknownCountry extends RuleViolation
{
    public function __construct(public readonly string $countryCode)
    {
        parent::__construct(\sprintf('There is no country with the code "%s".', $countryCode), 'orillacart_unknown_country');
    }
}
