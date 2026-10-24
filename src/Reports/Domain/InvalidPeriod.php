<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Domain;

use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\Text;

final class InvalidPeriod extends RuleViolation
{
    /**
     * @param list<int|float|string|\Stringable> $values
     */
    public function __construct(string|Text $message, array $values = [])
    {
        parent::__construct($message, 'orillacart_invalid_period', values: $values);
    }
}
