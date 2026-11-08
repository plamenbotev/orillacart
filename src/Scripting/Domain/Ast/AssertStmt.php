<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `assert quote.amount == money("5"), "the quote"`: fails with kind `assertion` when the test is false. */
final readonly class AssertStmt extends Stmt
{
    public function __construct(public Expr $test, public ?Expr $message, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
