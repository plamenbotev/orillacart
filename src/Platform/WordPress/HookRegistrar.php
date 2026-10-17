<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use Psr\Container\ContainerInterface;

/**
 * Hooks in the #[AsHook] classes. Each class is built the first time its hook fires.
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
                add_filter($hook['hook'], fn(mixed ...$arguments): mixed => $this->call($hook, $arguments), $hook['priority'], $hook['arguments']);
            } else {
                add_action($hook['hook'], function (mixed ...$arguments) use ($hook): void {
                    $this->call($hook, $arguments);
                }, $hook['priority'], $hook['arguments']);
            }
        }
    }

    /**
     * @param array{service: string, hook: string, method: string, priority: int, arguments: int, filter: bool} $hook
     * @param array<array-key, mixed>                                                                         $arguments
     */
    private function call(array $hook, array $arguments): mixed
    {
        $method = [$this->services->get($hook['service']), $hook['method']];
        if (!\is_callable($method)) {
            throw new \LogicException(\sprintf('%s::%s() is not callable.', $hook['service'], $hook['method']));
        }

        return $method(...$arguments);
    }
}
