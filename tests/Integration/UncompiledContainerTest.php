<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Jobs\Application\Dto\LaneView;
use OrillaCart\Jobs\Application\JobOverview;
use OrillaCart\Jobs\Infrastructure\WordPress\RunJobs;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use PHPUnit\Framework\TestCase;

/**
 * A read-only plugin folder in development, with nowhere to cache the container: the plugin runs on the
 * container as built, whose service locators do not know their services' classes. It works the same.
 */
final class UncompiledContainerTest extends TestCase
{
    public function testThePluginActivatesAndKnowsItsJobsOnTheContainerAsBuilt(): void
    {
        $context = new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION);
        $built = Kernel::buildContainer($context->dir);
        $built->compile();
        $built->set(PluginContext::class, $context);
        $compiled = new Kernel($context)->container();

        $lifecycle = $built->get(PluginLifecycle::class);
        self::assertInstanceOf(PluginLifecycle::class, $lifecycle);
        wp_clear_scheduled_hook(RunJobs::hook());
        $lifecycle->activate();
        self::assertNotFalse(wp_next_scheduled(RunJobs::hook()), 'its scheduled tasks are on the schedule');

        // The workers serve the same lanes: the handlers' classes say which.
        $lanes = static function (mixed $overview): array {
            \assert($overview instanceof JobOverview);

            return array_map(static fn(LaneView $lane): string => $lane->queue, $overview->overview()->lanes);
        };
        self::assertNotSame([], $lanes($built->get(JobOverview::class)));
        self::assertSame($lanes($compiled->get(JobOverview::class)), $lanes($built->get(JobOverview::class)));
    }
}
