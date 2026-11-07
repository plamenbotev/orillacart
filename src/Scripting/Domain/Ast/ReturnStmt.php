<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

final readonly class ReturnStmt extends Stmt
{
    public function __construct(public ?Expr $value, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
