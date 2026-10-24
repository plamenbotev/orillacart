<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use OrillaCart\Platform\I18n\GettextTranslations;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Http\HttpKernel;
use OrillaCart\Shared\Http\Middleware\AuthorizationMiddleware;
use OrillaCart\Shared\Http\Middleware\CsrfMiddleware;
use OrillaCart\Shared\Http\Middleware\ErrorMiddleware;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\ActionDispatcher;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Security\RouteAccess;
use OrillaCart\Tests\Fixtures\Http\FakeAuthorization;
use OrillaCart\Tests\Fixtures\Http\FakeCsrfProtection;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;
use OrillaCart\Tests\Fixtures\Http\RecordingAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class HttpKernelTest extends TestCase
{
    private FakeAuthorization $authorization;
    private FakeCsrfProtection $csrf;
    private MemoryLogger $logger;
    private RecordingAction $action;

    protected function setUp(): void
    {
        $this->authorization = new FakeAuthorization();
        $this->csrf = new FakeCsrfProtection(['wp_rest' => 'good-token']);
        $this->logger = new MemoryLogger();
        $this->action = new RecordingAction();
    }

    public function testVisitorsGet401OnProtectedRoutes(): void
    {
        $response = $this->send('GET', $this->route(['GET'], 'manage_orillacart'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('orillacart_unauthorized', $this->body($response)['code']);
        self::assertSame(0, $this->action->calls);
    }

    public function testUsersWithoutTheCapabilityGet403(): void
    {
        $this->authorization->loggedIn = true;
        $this->authorization->capabilities = ['read'];

        $response = $this->send('GET', $this->route(['GET'], 'manage_orillacart'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('orillacart_forbidden', $this->body($response)['code']);
        self::assertSame(0, $this->action->calls);
    }

    public function testPublicRoutesServeVisitors(): void
    {
        $response = $this->send('GET', $this->route(['GET'], Route::PUBLIC));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->action->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeMethods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('unsafeMethods')]
    public function testUnsafeMethodsNeedANonceEvenOnPublicRoutes(string $method): void
    {
        $route = $this->route([$method], Route::PUBLIC);

        self::assertSame(403, $this->send($method, $route)->getStatusCode());
        self::assertSame(403, $this->send($method, $route, ['X-WP-Nonce' => 'forged'])->getStatusCode());
        self::assertSame(0, $this->action->calls);

        self::assertSame(200, $this->send($method, $route, ['X-WP-Nonce' => 'good-token'])->getStatusCode());
        self::assertSame(1, $this->action->calls);
    }

    public function testTheNonceMayComeAsAFormField(): void
    {
        $request = new ServerRequest('POST', '/x')
            ->withParsedBody(['_wpnonce' => 'good-token'])
            ->withAttribute(RouteDefinition::ATTRIBUTE, $this->route(['POST'], Route::PUBLIC));

        self::assertSame(200, $this->kernel()->handle($request)->getStatusCode());
    }

    public function testTheNonceIsCheckedForTheRoutesOwnAction(): void
    {
        $route = new RouteDefinition(RecordingAction::class, '/cart', ['POST'], Route::PUBLIC, 'orillacart_cart');

        self::assertSame(403, $this->send('POST', $route, ['X-WP-Nonce' => 'good-token'])->getStatusCode());
    }

    public function testSignedRoutesLeaveTheCheckToTheirAction(): void
    {
        $route = new RouteDefinition(RecordingAction::class, '/payments/paypal/webhook', ['POST'], Route::PUBLIC, Route::REST_NONCE, signed: true);

        self::assertSame(200, $this->send('POST', $route)->getStatusCode());
        self::assertSame(1, $this->action->calls);
    }

    public function testRequestsWithoutCookiesNeedNoNonce(): void
    {
        $this->csrf->required = false;

        self::assertSame(200, $this->send('POST', $this->route(['POST'], Route::PUBLIC))->getStatusCode());
    }

    public function testCapabilityIsCheckedBeforeTheNonce(): void
    {
        $response = $this->send('PUT', $this->route(['PUT'], 'manage_orillacart'), ['X-WP-Nonce' => 'good-token']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testDomainErrorsBecomeClientErrors(): void
    {
        $this->action->throw = new NotFound('No such product.', 'orillacart_product_not_found');
        $response = $this->send('GET', $this->route(['GET'], Route::PUBLIC));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['code' => 'orillacart_product_not_found', 'message' => 'No such product.', 'data' => ['status' => 404]], $this->body($response));

        $this->action->throw = new RuleViolation('Out of stock.');
        self::assertSame(422, $this->send('GET', $this->route(['GET'], Route::PUBLIC))->getStatusCode());
        self::assertSame([], $this->logger->records);
    }

    public function testUnexpectedErrorsAreLoggedAndHidden(): void
    {
        $this->action->throw = new \RuntimeException('SQLSTATE[42S02]: secret table name');

        $response = $this->send('GET', $this->route(['GET'], Route::PUBLIC));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('secret', (string) $response->getBody());
        self::assertCount(1, $this->logger->records);
        self::assertSame('error', $this->logger->records[0]['level']);
        self::assertSame($this->action->throw, $this->logger->records[0]['context']['exception']);
    }

    public function testARequestWithoutARouteNeverReachesAnAction(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('GET', '/x'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(0, $this->action->calls);
    }

    public function testSecurityMiddlewareRunsFirst(): void
    {
        self::assertSame(
            [ErrorMiddleware::class, AuthorizationMiddleware::class, CsrfMiddleware::class],
            \array_slice($this->kernel()->middleware(), 0, 3),
        );
    }

    /**
     * @param list<string> $methods
     */
    private function route(array $methods, string $capability): RouteDefinition
    {
        return new RouteDefinition(RecordingAction::class, '/test', $methods, $capability, Route::REST_NONCE);
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $method, RouteDefinition $route, array $headers = []): ResponseInterface
    {
        $request = new ServerRequest($method, '/wp-json/orillacart/v1' . $route->path, $headers)
            ->withAttribute(RouteDefinition::ATTRIBUTE, $route);

        return $this->kernel()->handle($request);
    }

    private function kernel(): HttpKernel
    {
        $psr17 = new Psr17Factory();
        $responder = new JsonResponder(new SerializerFactory()->create(), $psr17, $psr17);

        return new HttpKernel(
            new ErrorMiddleware($responder, $this->logger, new GettextTranslations()),
            new AuthorizationMiddleware(new RouteAccess($this->authorization)),
            new CsrfMiddleware($this->csrf),
            [],
            new ActionDispatcher(new ServiceLocator([RecordingAction::class => fn(): RecordingAction => $this->action])),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function body(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
