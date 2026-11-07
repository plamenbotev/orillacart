<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

final readonly class MapLit extends Expr
{
    /** @param list<array{Expr, Expr}> $pairs key then value; a bare or quoted key is a Str */
    public function __construct(public array $pairs, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
