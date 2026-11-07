<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/**
 * A script file: its declarations in order.
 */
final readonly class Program extends Node
{
    /** @param list<Declaration> $declarations */
    public function __construct(public array $declarations, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
