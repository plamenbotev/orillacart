<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http;

use OrillaCart\Shared\Http\Middleware\AuthorizationMiddleware;
use OrillaCart\Shared\Http\Middleware\CsrfMiddleware;
use OrillaCart\Shared\Http\Middleware\ErrorMiddleware;
use OrillaCart\Shared\Http\Routing\ActionDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The one entry point for every request the plugin answers. Adapters (REST, pages) match a route,
 * put it on the request and hand the request here.
 *
 * Errors, capability and nonce checks are fixed constructor parameters rather than configuration,
 * so no route can be served without them. Other middleware (tagged orillacart.middleware) runs after them.
 */
final readonly class HttpKernel implements RequestHandlerInterface
{
    public const string MIDDLEWARE_TAG = 'orillacart.middleware';

    /** @var list<MiddlewareInterface> */
    private array $pipeline;

    /**
     * @param iterable<MiddlewareInterface> $middleware
     */
    public function __construct(
        ErrorMiddleware $errors,
        AuthorizationMiddleware $authorization,
        CsrfMiddleware $csrf,
        #[AutowireIterator(self::MIDDLEWARE_TAG)]
        iterable $middleware,
        private ActionDispatcher $dispatcher,
    ) {
        $this->pipeline = [$errors, $authorization, $csrf, ...iterator_to_array($middleware, false)];
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->next(0)->handle($request);
    }

    /** @return list<class-string<MiddlewareInterface>> the middleware in the order it runs */
    public function middleware(): array
    {
        return array_values(array_map(static fn(MiddlewareInterface $m): string => $m::class, $this->pipeline));
    }

    private function next(int $index): RequestHandlerInterface
    {
        if (!isset($this->pipeline[$index])) {
            return $this->dispatcher;
        }

        $middleware = $this->pipeline[$index];
        $next = fn(): RequestHandlerInterface => $this->next($index + 1);

        return new readonly class ($middleware, $next) implements RequestHandlerInterface {
            /** @param \Closure(): RequestHandlerInterface $next */
            public function __construct(private MiddlewareInterface $middleware, private \Closure $next) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, ($this->next)());
            }
        };
    }
}
