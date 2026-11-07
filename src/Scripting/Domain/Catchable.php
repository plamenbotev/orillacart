<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain;

/** What a script's `try` catches: a failure it raised, or an error a value or the host raised. Never a budget. */
interface Catchable extends \Throwable
{
    /** @return array{kind: string, message: string, data: mixed} the value the `catch` variable gets */
    public function value(): array;
}
