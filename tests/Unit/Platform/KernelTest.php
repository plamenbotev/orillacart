<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform;

use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\LifecycleListener;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use PHPUnit\Framework\TestCase;

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
}
