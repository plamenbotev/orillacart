<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `fn(params…) { body }`, named in a declaration or a lambda in an expression. */
final readonly class FnExpr extends Expr
{
    /**
     * @param list<string> $params
     * @param list<Stmt>   $body
     */
    public function __construct(public array $params, public array $body, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
