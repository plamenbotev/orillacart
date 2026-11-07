<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `fail unavailable("…")`: throws the error value. */
final readonly class FailStmt extends Stmt
{
    public function __construct(public Expr $error, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
