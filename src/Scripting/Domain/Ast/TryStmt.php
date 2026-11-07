<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** `try { … } catch e { … }`: catches failures and errors, never a budget that ran out. */
final readonly class TryStmt extends Stmt
{
    /**
     * @param list<Stmt> $body
     * @param list<Stmt> $handler
     */
    public function __construct(public array $body, public ?string $variable, public array $handler, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
