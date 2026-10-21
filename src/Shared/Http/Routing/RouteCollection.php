<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Routing;

/**
 * Every route of the plugin, collected from #[Route] attributes when the container is compiled.
 */
final readonly class RouteCollection
{
    /** REST namespace all routes are registered under. */
    public const string REST_NAMESPACE = 'orillacart/v1';

    /** @var list<RouteDefinition> */
    private array $routes;

    /**
     * @param list<array{action: string, path: string, methods: list<string>, capability: string, nonce: string, requirements: array<string, string>, signed: bool, guard: ?string, changes: bool}> $routes
     */
    public function __construct(array $routes)
    {
        $this->routes = array_map(RouteDefinition::fromArray(...), $routes);
    }

    /** @return list<RouteDefinition> */
    public function all(): array
    {
        return $this->routes;
    }
}
