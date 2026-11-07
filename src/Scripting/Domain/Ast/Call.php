<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `callee(args…)`: a package function, a host function, or a builtin. */
final readonly class Call extends Expr
{
    /** @param list<Expr> $args */
    public function __construct(public Expr $callee, public array $args, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
