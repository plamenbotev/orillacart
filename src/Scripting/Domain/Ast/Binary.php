<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `a op b` for the arithmetic, comparison, logic, `~`, `??`, `..`, `in`, `not in`, `starts with`, `ends with`. */
final readonly class Binary extends Expr
{
    public function __construct(public string $op, public Expr $left, public Expr $right, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
