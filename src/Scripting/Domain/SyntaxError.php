<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain;

/** The source cannot be read: where, and what was expected. */
final class SyntaxError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $sourceLine, public readonly int $sourceColumn)
    {
        parent::__construct(\sprintf('%s (line %d, column %d)', $message, $sourceLine, $sourceColumn));
    }
}
