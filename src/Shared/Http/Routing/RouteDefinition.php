<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Routing;

use Psr\Http\Message\ServerRequestInterface;

/**
 * A route as the kernel knows it: the declaration plus the service that handles it.
 * Checked when the container is compiled, so a malformed route fails the build, not a request.
 */
final readonly class RouteDefinition
{
    /** Request attribute that carries the matched route through the middleware pipeline. */
    public const string ATTRIBUTE = '_orillacart_route';

    private const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var list<string> */
    public array $methods;

    /**
     * @param list<string>          $methods
     * @param array<string, string> $requirements
     */
    public function __construct(
        public string $action,
        public string $path,
        array $methods,
        public string $capability,
        public string $nonce,
        public array $requirements = [],
        public bool $signed = false,
        public ?string $guard = null,
        /** False when an unsafe method only works something out: not a change to log. */
        public bool $changes = true,
    ) {
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException(\sprintf('Route path "%s" of %s must start with "/".', $path, $action));
        }
        if ($methods === []) {
            throw new \InvalidArgumentException(\sprintf('Route %s of %s lists no HTTP methods.', $path, $action));
        }
        $methods = array_map(strtoupper(...), $methods);
        foreach ($methods as $method) {
            if (!\in_array($method, self::METHODS, true)) {
                throw new \InvalidArgumentException(\sprintf('Route %s of %s uses unknown method "%s".', $path, $action, $method));
            }
        }
        if (trim($capability) === '') {
            throw new \InvalidArgumentException(\sprintf('Route %s of %s needs a capability or Route::PUBLIC.', $path, $action));
        }
        if (trim($nonce) === '') {
            throw new \InvalidArgumentException(\sprintf('Route %s of %s needs a nonce action.', $path, $action));
        }
        if ($guard !== null && preg_match('/^[a-z][a-z_]{0,39}$/', $guard) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Route %s of %s has a guard "%s" that is not a name.', $path, $action, $guard));
        }
        if ($signed && $capability !== Route::PUBLIC) {
            throw new \InvalidArgumentException(\sprintf('Route %s of %s is signed, so it must be public.', $path, $action));
        }
        foreach (array_keys($requirements) as $name) {
            if (!str_contains($path, '{' . $name . '}')) {
                throw new \InvalidArgumentException(\sprintf('Route %s of %s has a requirement for unknown placeholder "%s".', $path, $action, $name));
            }
        }

        $this->methods = array_values(array_unique($methods));
    }

    public static function fromAttribute(string $action, Route $route): self
    {
        return new self($action, $route->path, $route->methods, $route->capability, $route->nonce, $route->requirements, $route->signed, $route->guard, $route->changes);
    }

    /**
     * The matched route the adapter put on the request. A request without one never reaches an action.
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        $route = $request->getAttribute(self::ATTRIBUTE);
        if (!$route instanceof self) {
            throw new \LogicException('The request reached the HTTP kernel without a matched route.');
        }

        return $route;
    }

    public function isPublic(): bool
    {
        return $this->capability === Route::PUBLIC;
    }

    /** Whether a request needs a nonce: unsafe methods do, unless the action verifies a signature instead. */
    public function needsNonce(string $method): bool
    {
        return !$this->signed && !$this->isSafe($method);
    }

    public function isSafe(string $method): bool
    {
        return \in_array(strtoupper($method), self::SAFE_METHODS, true);
    }

    /**
     * The path as a regular expression with named groups, the form WordPress REST routes use.
     *
     * @return non-falsy-string
     */
    public function pattern(): string
    {
        $pattern = (string) preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            fn(array $match): string => \sprintf('(?P<%s>%s)', $match[1], $this->requirements[$match[1]] ?? '[^/]+'),
            $this->path,
        );
        if (!str_starts_with($pattern, '/')) {
            throw new \LogicException(\sprintf('Route path "%s" did not produce a pattern.', $this->path));
        }

        return $pattern;
    }

    /**
     * @return array{action: string, path: string, methods: list<string>, capability: string, nonce: string, requirements: array<string, string>, signed: bool, guard: ?string, changes: bool}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'path' => $this->path,
            'methods' => $this->methods,
            'capability' => $this->capability,
            'nonce' => $this->nonce,
            'requirements' => $this->requirements,
            'signed' => $this->signed,
            'guard' => $this->guard,
            'changes' => $this->changes,
        ];
    }

    /**
     * @param array{action: string, path: string, methods: list<string>, capability: string, nonce: string, requirements: array<string, string>, signed: bool, guard: ?string, changes: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['action'], $data['path'], $data['methods'], $data['capability'], $data['nonce'], $data['requirements'], $data['signed'], $data['guard'], $data['changes']);
    }
}
