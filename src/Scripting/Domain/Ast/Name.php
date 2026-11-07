<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** A variable, a package function, or the root of a host namespace: which, the compiler decides. */
final readonly class Name extends Expr
{
    public function __construct(public string $name, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
