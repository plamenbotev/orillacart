<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Middleware\GuardMiddleware;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Security\HumanCheck;
use OrillaCart\Shared\Http\Security\RateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class GuardMiddlewareTest extends TestCase
{
    /** @var array<string, int> hits by guard and caller */
    private array $hits = [];
    private ?string $refusal = null;
    private int $limit = 2;
    /** @var list<array<array-key, mixed>> the bodies the action got */
    private array $bodies = [];

    public function testEachCallerSendsSoManyByAddressAndByCart(): void
    {
        $this->send(['email' => 'ada@example.com']);
        $this->send(['email' => 'ada@example.com'], cart: 'abc');

        self::assertSame(['accounts|ip:203.0.113.7' => 2, 'accounts|cart:' . hash('sha256', 'abc') => 1], $this->hits);
        try {
            $this->send(['email' => 'ada@example.com']);
            self::fail('a third from the address');
        } catch (HttpException $e) {
            self::assertSame([429, 'orillacart_too_many_requests', ['retry_after' => 60]], [$e->status, $e->errorCode, $e->details]);
        }
        self::assertCount(2, $this->bodies);
    }

    public function testTheHoneypotLeavesTheBodyAndAFilledOneOrAnAddOnRefuses(): void
    {
        $this->limit = 10;
        $this->send(['email' => 'ada@example.com', GuardMiddleware::HONEYPOT => '']);
        self::assertSame([['email' => 'ada@example.com']], $this->bodies, 'the action never sees it');

        foreach ([[[GuardMiddleware::HONEYPOT => 'http://spam.test'], null], [[], 'Prove you are human.']] as [$body, $refusal]) {
            $this->refusal = $refusal;
            try {
                $this->send(['email' => 'bot@example.com'] + $body);
                self::fail('refused');
            } catch (HttpException $e) {
                self::assertSame([422, 'orillacart_refused', $refusal ?? 'We could not take this. Try again.'], [$e->status, $e->errorCode, $e->getMessage()]);
            }
        }
        self::assertCount(1, $this->bodies);
    }

    public function testRoutesWithoutAGuardPassUntouched(): void
    {
        $this->send([GuardMiddleware::HONEYPOT => 'kept'], guard: null);

        self::assertSame([], $this->hits);
        self::assertSame([[GuardMiddleware::HONEYPOT => 'kept']], $this->bodies);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(array $body, ?string $cart = null, ?string $guard = 'accounts'): void
    {
        $test = $this;
        $limits = new class ($this->hits, $this->limit) implements RateLimiter {
            /** @param array<string, int> $hits */
            public function __construct(private array &$hits, private int $limit) {}

            public function hit(string $guard, string $caller): ?int
            {
                $key = "{$guard}|{$caller}";
                $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;

                return $this->hits[$key] > $this->limit ? 60 : null;
            }
        };
        $humans = new class ($this->refusal) implements HumanCheck {
            public function __construct(private ?string $refusal) {}

            public function refusal(string $guard, array $body): ?string
            {
                return $this->refusal;
            }
        };
        $request = new ServerRequest('POST', '/wp-json/orillacart/v1/accounts', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withParsedBody($body)
            ->withCookieParams($cart === null ? [] : ['orillacart_cart' => $cart])
            ->withAttribute(RouteDefinition::ATTRIBUTE, new RouteDefinition('A', '/accounts', ['POST'], Route::PUBLIC, Route::REST_NONCE, guard: $guard));

        new GuardMiddleware($limits, $humans)->process($request, new class ($test) implements RequestHandlerInterface {
            public function __construct(private GuardMiddlewareTest $test) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->received($request->getParsedBody());

                return new Response(200);
            }
        });
    }

    public function received(mixed $body): void
    {
        $this->bodies[] = \is_array($body) ? $body : [];
    }
}
