<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform;

use Nyholm\Psr7\ServerRequest;
use OrillaCart\Platform\Kernel;
use OrillaCart\Shared\Http\HttpKernel;
use OrillaCart\Shared\Http\Middleware\AuthorizationMiddleware;
use OrillaCart\Shared\Http\Middleware\CsrfMiddleware;
use OrillaCart\Shared\Http\Middleware\ErrorMiddleware;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Http\Security\CsrfProtection;
use OrillaCart\Tests\Fixtures\Http\FakeAuthorization;
use OrillaCart\Tests\Fixtures\Http\FakeCsrfProtection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Phase 1 gate: every route the plugin declares passes the capability and nonce middleware.
 * Runs against the real container, so a route added later is covered without touching this test.
 */
final class RouteSecurityGateTest extends TestCase
{
    /**
     * Routes a payment provider calls server to server, without a nonce: their action checks the provider's
     * signature with the provider. A new one has to be added here, on purpose.
     */
    private const array SIGNED = ['POST /payments/{gateway}/webhook'];

    /**
     * Routes anyone may call, logged in or not: the storefront's and the payment provider's. A new one has to be
     * added here, on purpose, after checking what it reveals or changes for a visitor.
     */
    private const array PUBLIC = [
        'GET /cart',
        'POST /cart/items',
        'PUT /cart/items/{id}',
        'DELETE /cart/items/{id}',
        'POST /cart/coupons',
        'DELETE /cart/coupons/{code}',
        'POST /checkout',
        'POST /checkout/summary',
        'GET /receipts/{key}',
        'POST /accounts',
        'GET /session',
        'GET /payments/{gateway}/return',
        'GET /payments/{gateway}/cancel',
        'POST /payments/{gateway}/webhook',
        'GET /countries',
        'GET /countries/{code}/states',
        // A published bundle's price and stock with the visitor's choices, as its page shows them.
        'POST /products/{id}/bundle-quote',
    ];

    private static ContainerBuilder $container;
    private static FakeAuthorization $authorization;
    private static FakeCsrfProtection $csrf;

    public static function setUpBeforeClass(): void
    {
        $builder = Kernel::buildContainer(\dirname(__DIR__, 3));
        foreach ([HttpKernel::class, RouteCollection::class] as $id) {
            $builder->getDefinition($id)->setPublic(true);
        }
        // The WordPress implementations are swapped for fakes the test controls.
        $builder->register('test.authorization', FakeAuthorization::class)->setSynthetic(true);
        $builder->register('test.csrf', FakeCsrfProtection::class)->setSynthetic(true);
        $builder->setAlias(Authorization::class, 'test.authorization');
        $builder->setAlias(CsrfProtection::class, 'test.csrf');
        $builder->compile();

        // Services built once hold these instances; tests change their state, never replace them.
        self::$authorization = new FakeAuthorization();
        self::$csrf = new FakeCsrfProtection();
        $builder->set('test.authorization', self::$authorization);
        $builder->set('test.csrf', self::$csrf);
        self::$container = $builder;
    }

    public function testThePluginDeclaresRoutes(): void
    {
        self::assertNotEmpty($this->routes());
    }

    public function testEveryRouteRunsTheSecurityMiddleware(): void
    {
        $kernel = self::$container->get(HttpKernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);

        self::assertSame(
            [ErrorMiddleware::class, AuthorizationMiddleware::class, CsrfMiddleware::class],
            \array_slice($kernel->middleware(), 0, 3),
        );
    }

    public function testEveryProtectedRouteRefusesVisitorsAndUsersWithoutTheCapability(): void
    {
        foreach ($this->routes() as $route) {
            if ($route->isPublic()) {
                continue;
            }
            foreach ($route->methods as $method) {
                $this->actAs(loggedIn: false, capabilities: [], nonces: [], csrfRequired: false);
                self::assertSame(401, $this->statusOf($route, $method), $method . ' ' . $route->path . ' served a visitor');

                $this->actAs(loggedIn: true, capabilities: [], nonces: [], csrfRequired: false);
                self::assertSame(403, $this->statusOf($route, $method), $method . ' ' . $route->path . ' served a user without ' . $route->capability);
            }
        }
    }

    public function testEveryUnsafeRouteRefusesMissingAndForgedNonces(): void
    {
        foreach ($this->routes() as $route) {
            foreach ($route->methods as $method) {
                if ($route->isSafe($method)) {
                    continue;
                }
                if (!$route->needsNonce($method)) {
                    self::assertContains("{$method} {$route->path}", self::SIGNED, "{$method} {$route->path} skips the nonce without being a known signed route");
                    continue;
                }
                $this->actAs(loggedIn: true, capabilities: [$route->capability], nonces: [$route->nonce => 'good-token'], csrfRequired: true);

                self::assertSame(403, $this->statusOf($route, $method), $method . ' ' . $route->path . ' ran without a nonce');
                self::assertSame(403, $this->statusOf($route, $method, 'forged'), $method . ' ' . $route->path . ' ran with a forged nonce');
            }
        }
    }

    public function testTheKnownSignedRoutesAreStillSigned(): void
    {
        $signed = [];
        foreach ($this->routes() as $route) {
            foreach ($route->methods as $method) {
                if (!$route->isSafe($method) && !$route->needsNonce($method)) {
                    $signed[] = "{$method} {$route->path}";
                }
            }
        }

        self::assertSame(self::SIGNED, $signed);
    }

    public function testThePublicRoutesAreTheKnownOnes(): void
    {
        $public = [];
        foreach ($this->routes() as $route) {
            if ($route->isPublic()) {
                foreach ($route->methods as $method) {
                    $public[] = "{$method} {$route->path}";
                }
            }
        }
        $known = self::PUBLIC;
        sort($public);
        sort($known);

        self::assertSame($known, $public);
    }

    /** @return list<RouteDefinition> */
    private function routes(): array
    {
        $routes = self::$container->get(RouteCollection::class);
        self::assertInstanceOf(RouteCollection::class, $routes);

        return $routes->all();
    }

    /**
     * @param list<string>          $capabilities
     * @param array<string, string> $nonces
     */
    private function actAs(bool $loggedIn, array $capabilities, array $nonces, bool $csrfRequired): void
    {
        self::$authorization->loggedIn = $loggedIn;
        self::$authorization->capabilities = $capabilities;
        self::$csrf->validTokens = $nonces;
        self::$csrf->required = $csrfRequired;
    }

    private function statusOf(RouteDefinition $route, string $method, ?string $nonce = null): int
    {
        $kernel = self::$container->get(HttpKernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);

        $path = (string) preg_replace('/\{[^}]+\}/', '1', $route->path);
        $request = new ServerRequest($method, '/wp-json/' . RouteCollection::REST_NAMESPACE . $path, $nonce === null ? [] : ['X-WP-Nonce' => $nonce])
            ->withAttribute(RouteDefinition::ATTRIBUTE, $route);

        return $kernel->handle($request)->getStatusCode();
    }
}
