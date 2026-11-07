<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `value|name(args…)` */
final readonly class Filter extends Expr
{
    /** @param list<Expr> $args */
    public function __construct(public Expr $value, public string $name, public array $args, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
