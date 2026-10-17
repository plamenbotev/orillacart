<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Security;

use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Routing\RouteDefinition;

/**
 * The one rule for who may call a route. The HTTP kernel enforces it for every request, and the REST
 * adapter also hands it to WordPress as the permission callback, so WordPress refuses on its own too.
 */
final readonly class RouteAccess
{
    public function __construct(private Authorization $authorization) {}

    /**
     * @throws HttpException 401 for visitors, 403 for users without the capability
     */
    public function check(RouteDefinition $route): void
    {
        if ($route->isPublic() || $this->authorization->isGranted($route->capability)) {
            return;
        }

        throw $this->authorization->isLoggedIn() ? HttpException::forbidden() : HttpException::unauthorized();
    }
}
