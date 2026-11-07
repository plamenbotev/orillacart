<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** null, true, false, a number. */
final readonly class Literal extends Expr
{
    public function __construct(public bool|int|float|null $value, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
