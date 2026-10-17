<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Middleware;

use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Security\RouteAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets a request reach its action only when the user holds the route's capability.
 */
final readonly class AuthorizationMiddleware implements MiddlewareInterface
{
    public function __construct(private RouteAccess $access) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->access->check(RouteDefinition::fromRequest($request));

        return $handler->handle($request);
    }
}
