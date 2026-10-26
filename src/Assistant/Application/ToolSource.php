<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Application\Dto\ToolOutcome;

/**
 * Where some of the assistant's tools come from: the shop's routes, the parts WordPress owns, the setup, add-ons.
 * The container finds each by this interface.
 */
interface ToolSource
{
    public const string TAG = 'orillacart.assistant_tools';

    /** @return list<ToolDefinition> */
    public function tools(): array;

    /**
     * Runs one of this source's tools. The caller may use it: AssistantTools checked.
     *
     * @param array<string, mixed> $arguments
     */
    public function run(ToolDefinition $tool, array $arguments, Caller $caller): ToolOutcome;
}
