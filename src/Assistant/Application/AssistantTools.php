<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Application\Dto\ToolOutcome;
use OrillaCart\Shared\Http\Security\Authorization;

/**
 * The tools a caller is offered, from every source, sorted by name so clients can cache the list; and calling one.
 * A read-only key is offered only the tools that read, and nobody is offered a tool whose capability they lack.
 */
final readonly class AssistantTools
{
    public function __construct(
        private ToolSources $sources,
        private Authorization $authorization,
    ) {}

    /** @return list<ToolDefinition> */
    public function offered(Caller $caller): array
    {
        $tools = [];
        foreach ($this->sources->all() as $source) {
            foreach ($source->tools() as $tool) {
                if ($this->offers($tool, $caller) && !isset($tools[$tool->name])) {
                    $tools[$tool->name] = $tool;
                }
            }
        }
        ksort($tools);

        return array_values($tools);
    }

    /**
     * Runs the tool, or answers null when the caller is offered none of that name.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $name, array $arguments, Caller $caller): ?ToolOutcome
    {
        foreach ($this->sources->all() as $source) {
            foreach ($source->tools() as $tool) {
                if ($tool->name === $name) {
                    return $this->offers($tool, $caller) ? $source->run($tool, $arguments, $caller) : null;
                }
            }
        }

        return null;
    }

    private function offers(ToolDefinition $tool, Caller $caller): bool
    {
        return (!$caller->readOnly || $tool->readOnly) && $this->authorization->isGranted($tool->capability);
    }
}
