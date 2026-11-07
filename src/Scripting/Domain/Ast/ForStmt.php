<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `for value in iterable`, `for key, value in iterable` */
final readonly class ForStmt extends Stmt
{
    /** @param list<Stmt> $body */
    public function __construct(public ?string $key, public string $value, public Expr $iterable, public array $body, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
