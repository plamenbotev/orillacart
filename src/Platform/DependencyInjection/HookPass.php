<?php

declare(strict_types=1);

namespace OrillaCart\Platform\DependencyInjection;

use OrillaCart\Platform\WordPress\HookRegistrar;
use OrillaCart\Shared\WordPress\AsHook;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hands the HookRegistrar every #[AsHook] declaration and a locator that builds the classes on demand.
 */
final class HookPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $hooks = [];
        $services = [];

        foreach (array_keys($container->findTaggedServiceIds(AsHook::TAG)) as $id) {
            $class = $container->getDefinition($id)->getClass() ?? $id;
            $reflection = $container->getReflectionClass($class);
            if ($reflection === null) {
                throw new InvalidArgumentException(\sprintf('Hook class %s does not exist.', $class));
            }

            foreach ($reflection->getAttributes(AsHook::class) as $attribute) {
                $hook = $attribute->newInstance();
                if (!$reflection->hasMethod($hook->method) || !$reflection->getMethod($hook->method)->isPublic()) {
                    throw new InvalidArgumentException(\sprintf('%s has no public method %s() for hook "%s".', $class, $hook->method, $hook->hook));
                }
                $hooks[] = [
                    'service' => $id,
                    'hook' => $hook->hook,
                    'method' => $hook->method,
                    'priority' => $hook->priority,
                    'arguments' => $hook->arguments,
                    'filter' => $hook->filter,
                ];
            }
            $services[$id] = new Reference($id);
        }

        // Public: a hook firing while a step runs as another site resolves its class from that site's registrar.
        $container->getDefinition(HookRegistrar::class)
            ->setPublic(true)
            ->setArgument('$hooks', $hooks)
            ->setArgument('$services', ServiceLocatorTagPass::register($container, $services));
    }
}
