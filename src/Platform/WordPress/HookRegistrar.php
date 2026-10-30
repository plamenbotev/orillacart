<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use Psr\Container\ContainerInterface;

/**
 * Hooks in the #[AsHook] classes. Each class is built the first time its hook fires, from the container of the site
 * the process is in at that moment: the booting site's, or, while a step runs as another site of a network
 * (SiteKernels), that site's, whose HookRegistrar holds the same classes for that site.
 */
final readonly class HookRegistrar implements Registrar
{
    /**
     * @param list<array{service: string, hook: string, method: string, priority: int, arguments: int, filter: bool}> $hooks set by HookPass
     */
    public function __construct(
        private array $hooks,
        private ContainerInterface $services,
    ) {}

    public function register(): void
    {
        foreach ($this->hooks as $hook) {
            if ($hook['filter']) {
                add_filter($hook['hook'], fn(mixed ...$arguments): mixed => $this->call($hook['service'], $hook['method'], $arguments), $hook['priority'], $hook['arguments']);
            } else {
                add_action($hook['hook'], function (mixed ...$arguments) use ($hook): void {
                    $this->call($hook['service'], $hook['method'], $arguments);
                }, $hook['priority'], $hook['arguments']);
            }
        }
    }

    /** The hook class as this registrar's container builds it. */
    public function service(string $id): object
    {
        $service = $this->services->get($id);
        \assert(\is_object($service));

        return $service;
    }

    /**
     * What a hook does when it fires: the method on the hook class of the site the process is in.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function call(string $service, string $method, array $arguments): mixed
    {
        $callable = [$this->resolver()->service($service), $method];
        if (!\is_callable($callable)) {
            throw new \LogicException(\sprintf('%s::%s() is not callable.', $service, $method));
        }

        return $callable(...$arguments);
    }

    /** This registrar, or the one of the site a step is running as. */
    private function resolver(): self
    {
        $current = SiteKernels::current();
        if ($current === null || !$current->has(self::class)) {
            return $this;
        }
        $registrar = $current->get(self::class);

        return $registrar instanceof self ? $registrar : $this;
    }
}
