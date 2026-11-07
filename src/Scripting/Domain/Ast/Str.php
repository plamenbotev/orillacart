<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

final readonly class Str extends Expr
{
    public function __construct(public string $value, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
