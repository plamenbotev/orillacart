<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Domain\PackageState;
use OrillaCart\Shared\Http\Routing\DynamicRoutes;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Security\Capability;

/**
 * The `route METHOD "/path" { who } fn(request)` declarations of the enabled extensions as REST routes
 * under `/x/<extension>/…`, through the same pipeline as the shop's own: authorization, nonce, guards, the change
 * log. A stopped extension keeps its routes, which answer 503, so a caller learns why rather than meeting a 404.
 *
 * @phpstan-type Declaration array{kind: string, words: list<string>, name: ?string, props: array<string, mixed>, functions: array<string, string>, nested: list<array<string, mixed>>, handler: ?string}
 */
final class ScriptedRoutes implements DynamicRoutes
{
    public const string PREFIX = '/x/';
    /** The guard every extension route shares: 120 calls per caller in 10 minutes (TransientRateLimiter). */
    public const string GUARD = 'extensions';
    /** The action behind every scripted route, named as a string: it is in the Http layer, which this one does not use. */
    public const string ACTION = 'OrillaCart\\Packages\\Http\\ScriptedRouteAction';

    /** @var array<string, array{LoadedPackage, Declaration}>|null by method and path */
    private ?array $handlers = null;

    public function __construct(private readonly PackageService $packages) {}

    public function routes(): array
    {
        // Read anew each time the REST routes register: an extension enabled since is in, one uninstalled is out.
        $this->handlers = null;
        $routes = [];
        foreach ($this->handlers() as $key => [$loaded, $declaration]) {
            [$method, $path] = explode(' ', $key, 2);
            $props = $declaration['props'];
            $who = $props['who'] ?? null;
            $who = \is_array($who) ? ($who['$spec'] ?? 'manager') : ($who ?? 'manager');
            try {
                $routes[] = new RouteDefinition(
                    self::ACTION,
                    $path,
                    [$method],
                    match ($who) {
                        'public' => Route::PUBLIC,
                        'account' => Capability::ACCOUNT,
                        default => Capability::MANAGE_SHOP,
                    },
                    'wp_rest',
                    self::requirements($path),
                    false,
                    self::GUARD,
                    $method !== 'GET',
                );
            } catch (\InvalidArgumentException $e) {
                // One route the shop cannot take does not keep the site's REST API from registering.
                $this->packages->failed($loaded->package->slug(), \sprintf('The route %s %s cannot be registered: %s', $method, $declaration['name'] ?? '', $e->getMessage()));
            }
        }

        return $routes;
    }

    /**
     * The extension and declaration a request is for.
     *
     * @return array{LoadedPackage, Declaration}|null
     */
    public function handler(string $method, string $path): ?array
    {
        return $this->handlers()[$method . ' ' . $path] ?? null;
    }

    /**
     * @return array<string, array{LoadedPackage, Declaration}>
     */
    private function handlers(): array
    {
        if ($this->handlers !== null) {
            return $this->handlers;
        }
        $this->handlers = [];
        foreach ($this->packages->loaded(PackageState::Stopped) as $loaded) {
            foreach ($loaded->manifest->ofKind('route') as $declaration) {
                $method = strtoupper($declaration['words'][0] ?? 'GET');
                if (!\in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) || $declaration['handler'] === null) {
                    continue;
                }
                $path = '/' . trim((string) $declaration['name'], '/');
                if (preg_match('#^(/[A-Za-z0-9_{}.-]+)+$#', $path) !== 1 && $path !== '/') {
                    continue;
                }
                $this->handlers[$method . ' ' . self::PREFIX . $loaded->package->slug() . ($path === '/' ? '' : $path)] = [$loaded, $declaration];
            }
        }

        return $this->handlers;
    }

    /**
     * Every `{name}` in the path takes one segment.
     *
     * @return array<string, string>
     */
    private static function requirements(string $path): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches);

        return array_fill_keys($matches[1], '[^/]+');
    }
}
