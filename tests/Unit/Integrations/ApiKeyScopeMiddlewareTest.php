<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Integrations;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OrillaCart\Integrations\Application\Dto\AuthenticatedKey;
use OrillaCart\Integrations\Domain\ApiKeyScope;
use OrillaCart\Integrations\Infrastructure\ApiKeyScopeMiddleware;
use OrillaCart\Integrations\Infrastructure\WordPress\ApiKeyRequest;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A key reaches what its route lets keys reach, whatever the route's path.
 */
final class ApiKeyScopeMiddlewareTest extends TestCase
{
    public function testAKeyIsKeptFromARouteThatIsAManagersLoggedInOnly(): void
    {
        $route = new RouteDefinition('A', '/anything/with-secrets', ['GET'], 'manage_orillacart', 'wp_rest', keys: false);

        try {
            $this->process($route, 'GET', ApiKeyScope::ReadWrite);
            self::fail('A key reached a route kept from keys.');
        } catch (HttpException $e) {
            self::assertSame([403, 'orillacart_api_key_forbidden'], [$e->status, $e->errorCode]);
        }
        self::assertSame(200, $this->process($route, 'GET', null)->getStatusCode(), 'a manager logged in reaches it');
    }

    public function testAKeyReachesARouteOpenToKeysWithinItsScope(): void
    {
        $route = new RouteDefinition('A', '/api-keys-report', ['GET', 'PUT'], 'manage_orillacart', 'wp_rest');

        self::assertSame(200, $this->process($route, 'GET', ApiKeyScope::Read)->getStatusCode(), 'the path says nothing');
        $this->expectException(HttpException::class);
        $this->process($route, 'PUT', ApiKeyScope::Read);
    }

    private function process(RouteDefinition $route, string $method, ?ApiKeyScope $scope): ResponseInterface
    {
        $keys = new ApiKeyRequest();
        $keys->key = $scope === null ? null : new AuthenticatedKey(1, 1, $scope);

        return new ApiKeyScopeMiddleware($keys)->process(
            new ServerRequest($method, $route->path)->withAttribute(RouteDefinition::ATTRIBUTE, $route),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response();
                }
            },
        );
    }
}
