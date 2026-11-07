<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `value is name(args…)`, `value is not name` */
final readonly class Test extends Expr
{
    /** @param list<Expr> $args */
    public function __construct(public Expr $value, public string $name, public array $args, public bool $negated, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
