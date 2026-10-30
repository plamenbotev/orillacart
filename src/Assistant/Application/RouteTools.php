<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

use OrillaCart\Assistant\Application\Dto\RouteTool;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Routing\RouteInputs;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;

/**
 * The shop managers' routes as the assistant's tools: a name, what the tool does, its input as JSON Schema and
 * how risky it is. Worked out once, when the container is compiled (RoutePass), since it reads every
 * action's and DTO's code.
 */
final readonly class RouteTools
{
    /** @var array<string, RouteTool> by name, in the order they are listed */
    private array $tools;

    /**
     * @param list<array{name: string, title: string, description: string, area: string, method: string, path: string, placeholders: array<string, string>, input: 'body'|'query'|null, inputSchema: array<string, mixed>, readOnly: bool, destructive: bool, idempotent: bool, risk: ?string, capability: string, file: bool, wholeBody: ?string}> $tools what describe() gave
     */
    public function __construct(array $tools)
    {
        $byName = [];
        foreach ($tools as $tool) {
            $tool = RouteTool::fromArray($tool);
            $byName[$tool->name] = $tool;
        }
        $this->tools = $byName;
    }

    /** @return list<RouteTool> */
    public function all(): array
    {
        return array_values($this->tools);
    }

    public function get(string $name): ?RouteTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * The tools the routes make, sorted by name. A route for visitors or customers, a provider's signed route
     * and a route its action leaves out (#[Tool(leftOut: …)]) makes none.
     *
     * @param list<RouteDefinition> $routes
     *
     * @return list<array{name: string, title: string, description: string, area: string, method: string, path: string, placeholders: array<string, string>, input: 'body'|'query'|null, inputSchema: array<string, mixed>, readOnly: bool, destructive: bool, idempotent: bool, risk: ?string, capability: string, file: bool, wholeBody: ?string}>
     */
    public static function describe(array $routes): array
    {
        $tools = [];
        foreach ($routes as $route) {
            if (!self::isTool($route)) {
                continue;
            }
            [$body, $query] = RouteInputs::dtos($route->action);
            $file = self::takesFile($route->action);
            foreach ($route->methods as $method) {
                $tool = self::attribute($route->action, $route->path, $method);
                if ($tool?->leftOut !== null) {
                    continue;
                }
                $safe = $route->isSafe($method);
                $whole = $safe ? null : $tool?->wholeBody;
                $name = $tool->name ?? self::nameOf($route->action);
                if (\count($route->methods) > 1 && $tool?->name === null) {
                    $name .= '_' . strtolower($method);
                }
                if (isset($tools[$name])) {
                    throw new \LogicException(\sprintf('Two routes make the tool "%s": %s and %s %s. Name one with #[Tool(name: …)].', $name, $tools[$name]['path'], $method, $route->path));
                }
                $risk = self::risk($tool, $method);
                $readOnly = $safe || !$route->changes;
                $dto = $safe ? $query : $body;
                $tools[$name] = new RouteTool(
                    $name,
                    ucfirst(str_replace('_', ' ', $name)),
                    trim($tool->description ?? RouteInputs::summary($route->action)),
                    self::areaOf($route->action),
                    $method,
                    $route->path,
                    InputSchemas::placeholders($route->path, $dto),
                    $whole !== null ? 'body' : ($dto === null ? null : ($safe ? 'query' : 'body')),
                    $whole === null ? InputSchemas::forRoute($route->path, $route->requirements, $dto, $file && !$safe) : InputSchemas::wholeBody($route->path, $route->requirements, $whole),
                    $readOnly,
                    !$readOnly && $risk !== null,
                    $readOnly || \in_array($method, ['PUT', 'DELETE'], true),
                    $risk,
                    $route->capability,
                    $file && !$safe,
                    $whole === null ? null : (string) array_key_first($whole),
                )->toArray();
            }
        }
        ksort($tools);

        return array_values($tools);
    }

    /**
     * What the tool risks: what its #[Tool] says, none where it says Tool::NONE, and Tool::DELETING for a DELETE
     * that says nothing.
     */
    public static function risk(?Tool $tool, string $method): ?string
    {
        if ($tool?->risk === Tool::NONE) {
            return null;
        }

        return $tool->risk ?? ($method === 'DELETE' ? Tool::DELETING : null);
    }

    /** Whether the route is a shop manager's, and its action does not leave it out. */
    public static function isTool(RouteDefinition $route): bool
    {
        if ($route->signed || \in_array($route->capability, [Route::PUBLIC, Capability::ACCOUNT], true)) {
            return false;
        }

        return self::attribute($route->action, $route->path)?->leftOut === null;
    }

    /**
     * The action's #[Tool] for the route and method: the one naming both, else the one naming what it can, else
     * the one naming neither.
     */
    public static function attribute(string $action, string $path, ?string $method = null): ?Tool
    {
        if (!class_exists($action)) {
            return null;
        }
        $best = null;
        $bestScore = -1;
        foreach (new \ReflectionClass($action)->getAttributes(Tool::class) as $attribute) {
            $tool = $attribute->newInstance();
            if (($tool->path !== null && $tool->path !== $path) || ($tool->method !== null && $tool->method !== $method)) {
                continue;
            }
            $score = ($tool->path !== null ? 2 : 0) + ($tool->method !== null ? 1 : 0);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$tool, $score];
            }
        }

        return $best;
    }

    /** Whether the action reads an uploaded file: its tool then sends one as base64 or an address. */
    private static function takesFile(string $action): bool
    {
        $file = class_exists($action) ? new \ReflectionClass($action)->getFileName() : false;

        return \is_string($file) && str_contains((string) file_get_contents($file), "getUploadedFiles()['file']");
    }

    /** ChangeOrderStatusAction: change_order_status. */
    public static function nameOf(string $action): string
    {
        $short = substr($action, (int) strrpos($action, '\\') + 1);
        $short = (string) preg_replace('/Action$/', '', $short);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }

    /** OrillaCart\Ordering\Http\ChangeOrderStatusAction: Ordering. */
    public static function areaOf(string $action): string
    {
        return explode('\\', $action)[1] ?? '';
    }
}
