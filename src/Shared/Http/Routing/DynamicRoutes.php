<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Routing;

/**
 * Routes known only at run time, beside the ones compiled from the actions' attributes: the scripted extensions'
 * (design 56). Read when the REST routes register. Tagged by the kernel.
 */
interface DynamicRoutes
{
    public const string TAG = 'orillacart.dynamic_routes';

    /** @return list<RouteDefinition> */
    public function routes(): array;
}
