<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `test ? then : else`, `test ?: else` */
final readonly class Ternary extends Expr
{
    public function __construct(public Expr $test, public Expr $then, public Expr $else, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
