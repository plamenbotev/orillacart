<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `-x`, `not x` */
final readonly class Unary extends Expr
{
    public function __construct(public string $op, public Expr $operand, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
