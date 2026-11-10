<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Security;

/**
 * Whether the request in hand may only read: one authenticated with a read-only key or token. Code that runs on
 * someone else's word of what it does (an extension's GET route, a tool its author marked read-only) asks this
 * before it lets anything change.
 */
interface ReadOnlyRequest
{
    public function readOnly(): bool;
}
