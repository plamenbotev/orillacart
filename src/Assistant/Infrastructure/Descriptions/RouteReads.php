<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\Descriptions;

use OrillaCart\Assistant\Application\Dto\RouteTool;
use OrillaCart\Assistant\Application\RouteTools;
use OrillaCart\Assistant\Infrastructure\RouteCaller;

/**
 * Reads what a call is about through the shop's own read of its path, as the admin's screen does: with the same
 * checks as any call, changing nothing.
 */
final readonly class RouteReads
{
    public function __construct(
        private RouteTools $routes,
        private RouteCaller $caller,
    ) {}

    public function tool(string $name): ?RouteTool
    {
        return $this->routes->get($name);
    }

    /**
     * The answer of the GET at the path, its placeholders filled as the tool's call fills them; null where the
     * path has no read, or the read refused.
     *
     * @param array<string, mixed> $arguments the call's
     * @param array<string, mixed> $query     more for the read's query
     *
     * @return array<mixed>|null
     */
    public function read(string $path, RouteTool $for, array $arguments, array $query = []): ?array
    {
        foreach ($this->routes->all() as $read) {
            if ($read->method !== 'GET' || $read->path !== $path) {
                continue;
            }
            $values = $query;
            foreach ($read->placeholders as $placeholder => $argument) {
                $values[$argument] = $arguments[$for->placeholders[$placeholder] ?? $argument] ?? null;
            }
            $outcome = $this->caller->call($read, $values);
            $data = $outcome->isError() ? null : json_decode((string) json_encode($outcome->data), true);

            return \is_array($data) ? $data : null;
        }

        return null;
    }
}
