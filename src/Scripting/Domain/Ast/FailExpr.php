<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `x ?? fail refused("…")`: fails where a value was expected. */
final readonly class FailExpr extends Expr
{
    public function __construct(public Expr $error, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
