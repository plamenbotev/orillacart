<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\Text;

/**
 * The customer's choice of options does not describe something the shop sells.
 */
final class InvalidSelection extends RuleViolation
{
    /**
     * @param list<int|float|string|\Stringable> $values
     */
    public function __construct(string|Text $message, array $values = [])
    {
        parent::__construct($message, 'orillacart_invalid_selection', values: $values);
    }
}
