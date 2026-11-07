<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain;

/** The script did something a value cannot do: divided by zero, added a string to a map, read a member of null. */
final class ScriptError extends \RuntimeException implements Catchable
{
    public function value(): array
    {
        return ['kind' => 'error', 'message' => $this->getMessage(), 'data' => null];
    }
}
