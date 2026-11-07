<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/**
 * A declarative word with options: `text { longest: 200 }`, `choice { of: ["a"] }`, or a bare `read`. Only in
 * declarations; in code a bare word is a variable or a host namespace.
 */
final readonly class Spec extends Expr
{
    /** @param array<string, Expr> $props */
    public function __construct(public string $name, public array $props, int $line, int $column)
    {
        parent::__construct($line, $column);
    }
}
