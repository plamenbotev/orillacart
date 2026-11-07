<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application\Dto;

/** What the static check found: where, and what is wrong. */
final readonly class Finding
{
    public function __construct(public int $line, public int $column, public string $message) {}

    public function __toString(): string
    {
        return \sprintf('%s (line %d, column %d)', $this->message, $this->line, $this->column);
    }
}
