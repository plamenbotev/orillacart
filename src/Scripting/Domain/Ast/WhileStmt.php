<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

final readonly class WhileStmt extends Stmt
{
    /** @param list<Stmt> $body */
    public function __construct(public Expr $test, public array $body, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
