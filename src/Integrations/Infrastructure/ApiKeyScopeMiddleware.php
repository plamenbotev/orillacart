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
 * a quote or a plan, which change nothing), and no key makes,
 * lists or revokes keys, or sees or changes webhooks (their secrets sign what the shop sends, and their addresses
 * receive its orders): that takes a shop manager logged in.
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
            if (str_starts_with($route->path, '/api-keys')) {
                throw new HttpException(403, 'orillacart_api_key_forbidden', 'API keys are made and revoked by a shop manager logged in, not with a key.');
            }
            if (str_starts_with($route->path, '/webhooks')) {
                throw new HttpException(403, 'orillacart_api_key_forbidden', 'Webhooks are managed by a shop manager logged in, not with a key.');
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
