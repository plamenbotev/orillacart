<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use OrillaCart\Platform\WordPress\HookRegistrar;
use OrillaCart\Platform\WordPress\SiteKernels;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * A hook was added once, by the registrar of the container the plugin booted with; while a step runs as another
 * site of a network, the hook's class comes from that site's container. wp-env is not a network, so the sites are
 * containers here, each with a hook class of its own.
 */
final class HookRegistrarTest extends TestCase
{
    public function testAHookResolvesItsClassFromTheSiteTheProcessIsIn(): void
    {
        $siteA = new SiteHookClass('A');
        $siteB = new SiteHookClass('B');
        $boot = new HookRegistrar([['service' => SiteHookClass::class, 'hook' => 'orillacart_product_changed', 'method' => 'changed', 'priority' => 10, 'arguments' => 1, 'filter' => false]], self::locator([SiteHookClass::class => $siteA]));
        $other = new HookRegistrar([], self::locator([SiteHookClass::class => $siteB]));
        $containerB = self::locator([HookRegistrar::class => $other]);

        $boot->call(SiteHookClass::class, 'changed', [1]);
        SiteKernels::within($containerB, static function () use ($boot, $containerB): void {
            $boot->call(SiteHookClass::class, 'changed', [2]);
            self::assertSame($containerB, SiteKernels::current());
        });
        self::assertNull(SiteKernels::current(), 'the step is over');
        $boot->call(SiteHookClass::class, 'changed', [4]);

        self::assertSame([1, 4], $siteA->seen, 'the booting site hears what fires outside a step');
        self::assertSame([2], $siteB->seen, 'the site of the step hears what fires inside it');
    }

    protected function tearDown(): void
    {
        SiteKernels::pretend(null, null);
    }

    public function testAHookFiringWhileTheProcessIsSwitchedToAnotherSiteRunsOnThatSitesShopOrNotAtAll(): void
    {
        $now = 1;
        /** @var array<int, ContainerInterface> $containers */
        $containers = [];
        SiteKernels::pretend(static function () use (&$now): int {
            return $now;
        }, static function (int $site) use (&$containers): ?ContainerInterface {
            return $containers[$site] ?? null;
        });
        $siteA = new SiteHookClass('A');
        $siteB = new SiteHookClass('B');
        $boot = new HookRegistrar([], self::locator([SiteHookClass::class => $siteA]));
        $now = 2;
        $containers[2] = self::locator([HookRegistrar::class => new HookRegistrar([], self::locator([SiteHookClass::class => $siteB]))]);

        // WordPress deletes a network user's posts on site 2: site 1's product with that id is not touched.
        $boot->call(SiteHookClass::class, 'changed', [7]);
        self::assertSame([[], [7]], [$siteA->seen, $siteB->seen], 'site 2 runs the shop: its own class hears it');

        $now = 3;
        $boot->call(SiteHookClass::class, 'changed', [8]);
        self::assertSame('as given', $boot->call(SiteHookClass::class, 'title', ['as given'], filter: true), 'a filter on a site without the shop answers with what it was given');
        self::assertSame([[], [7]], [$siteA->seen, $siteB->seen], 'site 3 has no shop: nobody hears it');

        $boot->call(SiteHookClass::class, 'changed', [9], anySite: true);
        self::assertSame([9], $siteA->seen, 'a hook for any site runs on its own container wherever the process is');

        $now = 1;
        $boot->call(SiteHookClass::class, 'changed', [10]);
        self::assertSame([9, 10], $siteA->seen, 'back on the booting site');
    }

    public function testAHookFiringWhileItsSitesContainerIsMadeIsPassedOver(): void
    {
        $now = 1;
        $siteA = new SiteHookClass('A');
        $boot = new HookRegistrar([], self::locator([SiteHookClass::class => $siteA]));
        $now = 2;
        $made = 0;
        SiteKernels::pretend(static function () use (&$now): int {
            return $now;
        }, static function (int $site) use (&$boot, &$made): ?ContainerInterface {
            ++$made;
            // Making the container fires the hook again.
            $boot->call(SiteHookClass::class, 'changed', [1]);

            return null;
        });

        $boot->call(SiteHookClass::class, 'changed', [2]);

        self::assertSame([1, []], [$made, $siteA->seen]);
    }

    public function testAStepThatThrowsIsStillOver(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            SiteKernels::within(self::locator([]), static fn() => throw new \RuntimeException('The job failed.'));
        } finally {
            self::assertNull(SiteKernels::current());
        }
    }

    /**
     * @param array<string, object> $services
     */
    private static function locator(array $services): ContainerInterface
    {
        return new class ($services) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services) {}

            public function get(string $id): object
            {
                return $this->services[$id] ?? throw new \LogicException("No service {$id}.");
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
    }
}

/**
 * A hook class as one site's container builds it.
 */
final class SiteHookClass
{
    /** @var list<int> */
    public array $seen = [];

    public function __construct(public readonly string $site) {}

    public function changed(int $product): void
    {
        $this->seen[] = $product;
    }

    public function title(string $title): string
    {
        return $this->site . ': ' . $title;
    }
}
