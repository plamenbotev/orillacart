<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `object.name` */
final readonly class Member extends Expr
{
    public function __construct(public Expr $object, public string $name, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
