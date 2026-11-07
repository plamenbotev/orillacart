<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

final readonly class IfStmt extends Stmt
{
    /**
     * @param list<array{Expr, list<Stmt>}> $branches `if` then each `elif`
     * @param list<Stmt>|null               $else
     */
    public function __construct(public array $branches, public ?array $else, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
