<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

final class InvalidPeriod extends RuleViolation
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'orillacart_invalid_period');
    }
}
