<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

final readonly class LetStmt extends Stmt
{
    public function __construct(public string $name, public Expr $value, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
