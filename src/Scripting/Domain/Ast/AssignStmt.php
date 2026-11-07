<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `target = value`, the target a variable, a member or an index of one. */
final readonly class AssignStmt extends Stmt
{
    public function __construct(public Expr $target, public Expr $value, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
