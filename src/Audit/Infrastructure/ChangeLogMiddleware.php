<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Infrastructure;

use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Shared\Http\HttpKernel;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Writes each change a shop manager's request made into the change log: routes for shop managers only, not
 * those that only read or work something out (a quote), and only once the change was made (the answer is a success). The log never stands in
 * the change's way: a record that cannot be written is logged instead.
 */
#[AutoconfigureTag(HttpKernel::MIDDLEWARE_TAG)]
final readonly class ChangeLogMiddleware implements MiddlewareInterface
{
    /**
     * @param \Closure(): ChangeLog $log built only when a change is to be written: most requests read
     */
    public function __construct(
        #[AutowireServiceClosure(ChangeLog::class)]
        private \Closure $log,
        private Authorization $authorization,
        private LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $route = $request->getAttribute(RouteDefinition::ATTRIBUTE);
        $method = $request->getMethod();
        if (!$route instanceof RouteDefinition || $route->capability !== Capability::MANAGE_SHOP || $route->isSafe($method) || !$route->changes || $response->getStatusCode() >= 300) {
            return $response;
        }
        try {
            $body = json_decode((string) $request->getBody(), true);
            ($this->log)()->record($this->authorization->userId(), $method, $route->path, self::called($route, $request), \is_array($body) ? $body : []);
        } catch (\Throwable $e) {
            $this->logger->error('The change log could not record {route}: {message}', ['route' => "{$method} {$route->path}", 'message' => $e->getMessage()]);
        }

        return $response;
    }

    /** The route as called, below the shop's namespace whatever the site's permalinks: "/orders/12/notes". */
    private static function called(RouteDefinition $route, ServerRequestInterface $request): string
    {
        return (string) preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', static function (array $name) use ($request): string {
            $value = $request->getAttribute($name[1]);

            return \is_scalar($value) ? (string) $value : $name[0];
        }, $route->path);
    }
}
