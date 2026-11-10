<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure;

use OrillaCart\Integrations\Domain\ApiKeyScope;
use OrillaCart\Integrations\Infrastructure\WordPress\ApiKeyRequest;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\HttpKernel;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Holds a request made with an API key to the key's scope: a read-only key changes nothing (it may still ask for
 * a quote or a plan, which change nothing), and no key calls a route that is a shop manager's logged in only
 * (RouteDefinition::$keys): making, listing or revoking keys, seeing or changing webhooks (their secrets sign
 * what the shop sends, and their addresses receive its orders), and whatever else a route keeps from keys, as
 * every route kept from the AI assistant is unless it says otherwise.
 */
#[AutoconfigureTag(HttpKernel::MIDDLEWARE_TAG)]
final readonly class ApiKeyScopeMiddleware implements MiddlewareInterface
{
    public function __construct(private ApiKeyRequest $keys) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $this->keys->key;
        $route = $request->getAttribute(RouteDefinition::ATTRIBUTE);
        if ($key !== null && $route instanceof RouteDefinition) {
            if (!$route->keys) {
                throw new HttpException(403, 'orillacart_api_key_forbidden', 'This is a shop manager\'s logged in, not a key\'s: API keys, webhooks and the like are managed in the admin.');
            }
            // A route that only works something out (a quote, a plan, the assistant's endpoint, whose tools are
            // checked again as their own routes) changes nothing, whatever its method.
            if ($key->scope === ApiKeyScope::Read && !$route->isSafe($request->getMethod()) && $route->changes) {
                throw new HttpException(403, 'orillacart_read_only_key', 'This API key only reads.');
            }
        }

        return $handler->handle($request);
    }
}
