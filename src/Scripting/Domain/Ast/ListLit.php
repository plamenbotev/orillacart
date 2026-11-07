<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

final readonly class ListLit extends Expr
{
    /** @param list<Expr> $items */
    public function __construct(public array $items, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
