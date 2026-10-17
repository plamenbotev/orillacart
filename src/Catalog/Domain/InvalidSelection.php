<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * The customer's choice of options does not describe something the shop sells.
 */
final class InvalidSelection extends RuleViolation
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'orillacart_invalid_selection');
    }
}
