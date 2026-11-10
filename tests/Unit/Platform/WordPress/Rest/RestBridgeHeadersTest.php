<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress\Rest;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use OrillaCart\Platform\I18n\GettextTranslations;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Platform\WordPress\Rest\RestBridge;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\HttpKernel;
use OrillaCart\Shared\Http\Middleware\AuthorizationMiddleware;
use OrillaCart\Shared\Http\Middleware\CsrfMiddleware;
use OrillaCart\Shared\Http\Middleware\ErrorMiddleware;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\ActionDispatcher;
use OrillaCart\Shared\Http\Security\RouteAccess;
use OrillaCart\Tests\Fixtures\Http\FakeAuthorization;
use OrillaCart\Tests\Fixtures\Http\FakeCsrfProtection;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RestBridgeHeadersTest extends TestCase
{
    public function testHeadersAreCopiedWithDashedNames(): void
    {
        $request = RestBridge::withHeaders(new ServerRequest('GET', '/'), ['x_wp_nonce' => ['abc'], 'accept' => 'application/json']);

        self::assertSame('abc', $request->getHeaderLine('X-WP-Nonce'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testAHeaderWithAControlByteIsTheCallersMistakeAndTheKernelAnswers400(): void
    {
        $request = new ServerRequest('GET', '/wp-json/orillacart/v1/cart');
        try {
            RestBridge::withHeaders($request, ['x_note' => ["a\x01b"]]);
            self::fail('The header was let through.');
        } catch (HttpException $refused) {
            $logger = new MemoryLogger();
            $response = $this->kernel($logger)->refuse($request, $refused);
        }

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('orillacart_bad_request', $body['code']);
        self::assertSame([], $logger->records);
    }

    private function kernel(MemoryLogger $logger): HttpKernel
    {
        $psr17 = new Psr17Factory();
        $responder = new JsonResponder(new SerializerFactory()->create(), $psr17, $psr17);

        return new HttpKernel(
            new ErrorMiddleware($responder, $logger, new GettextTranslations()),
            new AuthorizationMiddleware(new RouteAccess(new FakeAuthorization())),
            new CsrfMiddleware(new FakeCsrfProtection([])),
            [],
            new ActionDispatcher(new ServiceLocator([])),
        );
    }
}
