<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure;

use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\RouteTool;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Application\Dto\ToolOutcome;
use OrillaCart\Assistant\Application\RouteTools;
use OrillaCart\Assistant\Application\ToolSource;

/**
 * The shop managers' routes as tools: a call runs its route (RouteCaller).
 */
final readonly class RouteToolSource implements ToolSource
{
    public function __construct(
        private RouteTools $routeTools,
        private RouteCaller $caller,
    ) {}

    public function tools(): array
    {
        return array_map(static fn(RouteTool $tool): ToolDefinition => new ToolDefinition(
            $tool->name,
            $tool->title,
            $tool->description,
            $tool->inputSchema,
            $tool->readOnly,
            $tool->destructive,
            $tool->idempotent,
            $tool->risk,
            $tool->area,
            $tool->capability,
        ), $this->routeTools->all());
    }

    public function run(ToolDefinition $tool, array $arguments, Caller $caller): ToolOutcome
    {
        $routeTool = $this->routeTools->get($tool->name);

        return $routeTool === null
            ? ToolOutcome::refused("The shop no longer has the tool {$tool->name}.")
            : $this->caller->call($routeTool, $arguments);
    }
}
