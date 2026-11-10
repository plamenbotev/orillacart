<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Platform\PluginContext;
use Psr\Container\ContainerInterface;

/**
 * Hooks in the #[AsHook] classes. Each class is built the first time its hook fires, from the container of the site
 * the process is in at that moment: the booting site's, or, while a step runs as another site of a network
 * (SiteKernels), that site's, whose HookRegistrar holds the same classes for that site.
 *
 * A registrar knows the site its container is for. A hook that fires while the process is on another site (inside
 * switch_to_blog(), by WordPress or another plugin) runs on that site's container when the shop runs there, and is
 * passed over otherwise: a filter answers with what it was given. A hook declared for any site (the outbound
 * request guard, API key sign-in) runs as outside a switch, whatever site the process is on.
 */
final readonly class HookRegistrar implements Registrar
{
    /** The site the container is for: the site the process was on when the container made its registrar. */
    private int $site;

    /**
     * @param list<array{service: string, hook: string, method: string, priority: int, arguments: int, filter: bool, anySite?: bool}> $hooks set by HookPass
     */
    public function __construct(
        private array $hooks,
        private ContainerInterface $services,
        private ?PluginContext $context = null,
    ) {
        $this->site = SiteKernels::processSite();
    }

    public function register(): void
    {
        foreach ($this->hooks as $hook) {
            $anySite = $hook['anySite'] ?? false;
            if ($hook['filter']) {
                add_filter($hook['hook'], fn(mixed ...$arguments): mixed => $this->call($hook['service'], $hook['method'], $arguments, $anySite, true), $hook['priority'], $hook['arguments']);
            } else {
                add_action($hook['hook'], function (mixed ...$arguments) use ($hook, $anySite): void {
                    $this->call($hook['service'], $hook['method'], $arguments, $anySite);
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
     * @param bool                    $anySite   on the class of the container it would come from, whatever site the process is on
     * @param bool                    $filter    a filter, which answers with its first argument when passed over
     */
    public function call(string $service, string $method, array $arguments, bool $anySite = false, bool $filter = false): mixed
    {
        $resolver = $this->resolver();
        if (!$anySite) {
            $site = SiteKernels::processSite();
            if ($site !== $resolver->site) {
                return SiteKernels::forHook(
                    $site,
                    $this->context,
                    static function (ContainerInterface $container) use ($service, $method, $arguments, $filter): mixed {
                        $registrar = $container->get(self::class);
                        \assert($registrar instanceof self);

                        return $registrar->call($service, $method, $arguments, false, $filter);
                    },
                    static fn(): mixed => $filter ? (array_values($arguments)[0] ?? null) : null,
                );
            }
        }
        $callable = [$resolver->service($service), $method];
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
