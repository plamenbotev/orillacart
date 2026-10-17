<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Rest;

use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Security\RouteAccess;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Registers every route with the WordPress REST API under orillacart/v1.
 *
 * Access is checked twice with the same RouteAccess rule: WordPress runs it as the permission callback
 * before anything of ours is built, and the HTTP kernel runs it again for every request it handles,
 * so neither layer depends on the other being in place.
 */
final readonly class RestRouteRegistrar implements Registrar
{
    /**
     * @param \Closure(): RestBridge $bridge built only when a route is called
     */
    public function __construct(
        private RouteCollection $routes,
        private RouteAccess $access,
        #[AutowireServiceClosure(RestBridge::class)]
        private \Closure $bridge,
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', $this->registerRoutes(...));
    }

    private function registerRoutes(): void
    {
        // WordPress keys REST endpoints by pattern, so routes sharing a path register together.
        $byPattern = [];
        foreach ($this->routes->all() as $route) {
            $byPattern[$route->pattern()][] = $route;
        }

        foreach ($byPattern as $routes) {
            register_rest_route(
                RouteCollection::REST_NAMESPACE,
                $routes[0]->pattern(),
                array_map($this->endpoint(...), $routes),
            );
        }
    }

    /**
     * @return array{methods: string, callback: callable, permission_callback: callable}
     */
    private function endpoint(RouteDefinition $route): array
    {
        return [
            'methods' => implode(', ', $route->methods),
            'callback' => fn(\WP_REST_Request $request): \WP_REST_Response => ($this->bridge)()->handle($route, $request),
            'permission_callback' => fn(): true|\WP_Error => $this->permission($route),
        ];
    }

    private function permission(RouteDefinition $route): true|\WP_Error
    {
        try {
            $this->access->check($route);
        } catch (HttpException $e) {
            return new \WP_Error($e->errorCode, $e->getMessage(), ['status' => $e->status]);
        }

        return true;
    }
}
