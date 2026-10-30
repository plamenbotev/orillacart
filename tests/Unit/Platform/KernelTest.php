<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform;

use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\WordPress\HookRegistrar;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Reference;

final class KernelTest extends TestCase
{
    public function testContainerCompilesWithoutWordPress(): void
    {
        $builder = Kernel::buildContainer(\dirname(__DIR__, 3));
        $builder->compile();

        self::assertTrue($builder->has(PluginLifecycle::class));
        self::assertTrue($builder->getDefinition(PluginLifecycle::class)->isPublic());
        self::assertTrue($builder->getDefinition(DatabaseMigrator::class)->isPublic());
    }

    public function testLifecycleListenersAreFoundByTheirInterface(): void
    {
        $builder = Kernel::buildContainer(\dirname(__DIR__, 3));
        // #[AutoconfigureTag] on the interface is applied by a compiler pass
        $builder->compile();

        self::assertArrayHasKey(DatabaseMigrator::class, $builder->findTaggedServiceIds(LifecycleListener::TAG));
    }

    public function testTheMigratorIsNotAmongTheListenersTheLifecycleRunsInOrderButRunsFirstOnItsOwn(): void
    {
        $builder = Kernel::buildContainer(\dirname(__DIR__, 3));
        $builder->compile();

        $arguments = $builder->getDefinition(PluginLifecycle::class)->getArguments();
        $listeners = $arguments['$listeners'] ?? $arguments[0];
        self::assertInstanceOf(IteratorArgument::class, $listeners);
        $ids = array_map(static function (mixed $listener): string {
            self::assertInstanceOf(Reference::class, $listener);

            return (string) $listener;
        }, $listeners->getValues());
        self::assertNotEmpty($ids);
        self::assertNotContains(DatabaseMigrator::class, $ids, 'a listener writing to a table must find it as the current build expects it, so the migrator is not run among them in alphabetical order');
        $migrator = $arguments['$migrator'] ?? $arguments[1];
        self::assertInstanceOf(Reference::class, $migrator);
        self::assertSame(DatabaseMigrator::class, (string) $migrator);
    }

    public function testEveryHookClassIsRegisteredInTheRegistrarOfItsContainerWhichIsPublic(): void
    {
        $builder = Kernel::buildContainer(\dirname(__DIR__, 3));
        $builder->compile();

        self::assertTrue($builder->getDefinition(HookRegistrar::class)->isPublic(), 'a hook that fires while a step runs as another site resolves its class from that site\'s registrar');
    }
}
