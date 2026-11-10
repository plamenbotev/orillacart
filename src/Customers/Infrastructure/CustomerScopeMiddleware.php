<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure;

use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\HttpKernel;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A customer's screens (`/customers/{id}…`: the customer, addresses, notes, groups, store credit, exemptions, the
 * welcome email) reach the shop's own customers only: not the site's administrators or managers, and on a network
 * not the users of its other sites. Whoever is not one is not found, as if there were no such user.
 */
#[AutoconfigureTag(HttpKernel::MIDDLEWARE_TAG)]
final readonly class CustomerScopeMiddleware implements MiddlewareInterface
{
    public function __construct(private Accounts $accounts) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute(RouteDefinition::ATTRIBUTE);
        if ($route instanceof RouteDefinition && str_starts_with($route->path, '/customers/{id}') && !$this->accounts->isShopCustomer(PathParameter::id($request))) {
            throw new HttpException(404, 'orillacart_customer_not_found', 'There is no such customer.');
        }

        return $handler->handle($request);
    }
}
