<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/** A piece of a script, with where it starts. */
abstract readonly class Node
{
    public function __construct(public int $line, public int $column) {}
}
