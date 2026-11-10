<?php

declare(strict_types=1);

namespace OrillaCart\Platform\DependencyInjection;

use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Shared\WordPress\CliCommand;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\ScheduledTask;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Writes on each tagged service the key its locator finds it by, read once from the class's own static method
 * (a job handler's type(), a block's name()): the locators read the tag's "key" attribute, and nothing calls the
 * method when the container is built.
 */
final class TaggedKeyPass implements CompilerPassInterface
{
    /** The tag's attribute that holds the key. */
    public const string KEY = 'key';

    /** The static method that names each tag's services. */
    private const array METHODS = [
        JobHandler::TAG => 'type',
        HealthCheck::TAG => 'id',
        CliCommand::TAG => 'name',
        BlockDefinition::TAG => 'name',
        ScheduledTask::TAG => 'hook',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::METHODS as $tag => $method) {
            foreach ($container->findTaggedServiceIds($tag) as $id => $tags) {
                $definition = $container->getDefinition($id);
                $class = $definition->getClass() ?? $id;
                $reflection = $container->getReflectionClass($class);
                if ($reflection === null || !$reflection->hasMethod($method) || !$reflection->getMethod($method)->isStatic() || !$reflection->getMethod($method)->isPublic()) {
                    throw new InvalidArgumentException(\sprintf('%s is tagged "%s" but has no public static method %s() to name it.', $class, $tag, $method));
                }
                $key = $reflection->getMethod($method)->invoke(null);
                if (!\is_string($key) || $key === '') {
                    throw new InvalidArgumentException(\sprintf('%s::%s() names it with nothing.', $class, $method));
                }
                $definition->clearTag($tag);
                foreach ($tags as $attributes) {
                    $definition->addTag($tag, [self::KEY => $key] + (\is_array($attributes) ? $attributes : []));
                }
            }
        }
    }
}
