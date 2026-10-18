<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Persistence;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * The database gave up on the work because other work held the same rows (a deadlock, or waiting too long for a
 * lock), even after it was tried again. Nothing was saved; trying again in a moment usually works.
 */
final class Contention extends RuleViolation
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The shop is very busy right now and could not finish this. Try again in a moment.', 'orillacart_busy', $previous);
    }
}
