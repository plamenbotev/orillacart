<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;

/**
 * What a call of some tool would do, in words, for the owner who is asked to say yes to it: its arguments alone
 * (a plan's fingerprint, an order's id) do not tell them. The container finds each by this interface.
 */
interface CallDescription
{
    public const string TAG = 'orillacart.assistant_call_descriptions';

    /**
     * Null for a tool this one does not describe.
     *
     * @param array<string, mixed> $arguments
     */
    public function describe(ToolDefinition $tool, array $arguments, Caller $caller): ?string;
}
