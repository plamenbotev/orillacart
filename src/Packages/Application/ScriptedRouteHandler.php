<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\ScriptResponse;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Packages\Domain\PackageState;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Security\RateLimiter;

/**
 * Answers a scripted route: finds the extension and declaration a method and path are for, runs the handler with
 * the request as a map, and gives back what `respond` made, every failure as an error response. A public route
 * that changes something is counted for its caller first (design 74): reads and signed-in callers are not, as the
 * shop's own are not; a flood is the site's firewall's to stop. What is counted is what a firewall cannot tell
 * from use: codes tried one after another, the extension's hourly email and HTTP allowance spent by one visitor.
 */
final readonly class ScriptedRouteHandler
{
    public function __construct(private ScriptedRoutes $routes, private ScriptRunner $runner, private ?RateLimiter $limits = null) {}

    /**
     * @param array<string, mixed> $request as the script sees it
     * @param string|null          $caller  who sent it over HTTP ("user:7", "ip:203.0.113.7"); null for the
     *                                      assistant's tools, which are no request to the route
     *
     * @throws HttpException 429 for a caller over the route's limit
     */
    public function handle(string $method, string $path, array $request, ?string $caller = null): ScriptResponse
    {
        $found = $this->routes->handler($method, $path);
        if ($found === null) {
            return new ScriptResponse(ScriptResponse::ERROR, 404, message: 'No extension answers here.');
        }
        [$loaded, $declaration] = $found;
        if ($loaded->package->state() === PackageState::Stopped) {
            return new ScriptResponse(ScriptResponse::ERROR, 503, message: \sprintf('The extension "%s" is stopped after failing; a manager enables it again.', $loaded->package->name()));
        }
        if ($caller !== null && $method !== 'GET' && ScriptedRoutes::who($declaration) === 'public') {
            $this->count($loaded->package->slug(), $method . ' ' . $path, ScriptedRoutes::limitOf($declaration), $caller);
        }
        try {
            $answer = $this->runner->run($loaded, (string) $declaration['handler'], [$request]);
        } catch (HandlerFailed $e) {
            return match ($e->kind) {
                HandlerFailed::REFUSED => new ScriptResponse(ScriptResponse::ERROR, 400, message: $e->getMessage()),
                HandlerFailed::UNAVAILABLE => new ScriptResponse(ScriptResponse::ERROR, 503, message: $e->getMessage()),
                default => new ScriptResponse(ScriptResponse::ERROR, 500, message: 'The extension failed; the shop logged it.'),
            };
        }
        if ($answer instanceof ScriptResponse) {
            if ($answer->kind === ScriptResponse::HTML) {
                return new ScriptResponse(ScriptResponse::HTML, $answer->status, $answer->data, PackageTemplates::PREFIX . $loaded->package->slug() . '/' . ltrim((string) $answer->template, '/'));
            }

            return $answer;
        }
        // A map or a value: JSON, as the common case needs no respond.json().
        $plain = Ops::plain($answer);

        return new ScriptResponse(ScriptResponse::JSON, 200, \is_array($plain) ? self::map($plain) : ['result' => $plain]);
    }

    /**
     * How long a visitor's answer from the route is kept, and what it depends on besides the request: the
     * extension's build and settings. Null for a route that keeps none (not public, not GET, or no `cache`).
     *
     * @return array{int, string}|null seconds, and the extension's part of the key
     */
    public function cached(string $method, string $path): ?array
    {
        $found = $method === 'GET' ? $this->routes->handler($method, $path) : null;
        if ($found === null || ScriptedRoutes::who($found[1]) !== 'public') {
            return null;
        }
        [$loaded, $declaration] = $found;
        $seconds = ScriptedRoutes::cacheOf($declaration);

        return $seconds === 0 || $loaded->package->state() !== PackageState::Enabled ? null : [$seconds, $loaded->package->slug() . '|' . md5($loaded->className . '|' . serialize($loaded->package->settings()))];
    }

    /**
     * The route's own limit counts it alone (a site's filter of extension_route overrides every route's); the shop's (TransientRateLimiter's extension_public) counts all of
     * the extension's public writes together, so a busy one leaves the others theirs.
     *
     * @param int|false|null $limit as the route declares it
     */
    private function count(string $extension, string $route, int|false|null $limit, string $caller): void
    {
        if ($limit === false || $this->limits === null) {
            return;
        }
        $wait = $limit === null
            ? $this->limits->hit(ScriptedRoutes::GUARD_PUBLIC, 'ext:' . $extension . '|' . $caller)
            : $this->limits->hit(ScriptedRoutes::GUARD_ROUTE, 'route:' . md5($extension . ' ' . $route) . '|' . $caller, [$limit, ScriptedRoutes::LIMIT_SECONDS]);
        if ($wait !== null) {
            throw new HttpException(429, 'orillacart_too_many_requests', 'That was a lot at once. Wait a little, then try again.', ['retry_after' => $wait]);
        }
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
