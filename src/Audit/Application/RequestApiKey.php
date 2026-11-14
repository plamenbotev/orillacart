<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Application;

/**
 * The API key the request came with, for the change log to name who made a change through it. Integrations, which
 * checks the keys, answers.
 */
interface RequestApiKey
{
    /** The key's name; null when the request came without one. */
    public function keyName(): ?string;
}
