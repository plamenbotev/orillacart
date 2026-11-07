<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `object[key]` */
final readonly class Index extends Expr
{
    public function __construct(public Expr $object, public Expr $key, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
