<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use OrillaCart\Platform\WordPress\AfterResponse\ShutdownWork;
use OrillaCart\Platform\WordPress\HookRegistrar;
use OrillaCart\Platform\WordPress\SiteKernels;
use OrillaCart\Shared\Lifecycle\AfterResponse;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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

    public function testWorkAHookDefersOnAnotherSiteRunsAtTheEndOfItsStepThere(): void
    {
        $now = 1;
        SiteKernels::pretend(static function () use (&$now): int {
            return $now;
        }, null);
        $boot = new HookRegistrar([], self::locator([]));
        $now = 2;
        $deferred = new ShutdownWork(new MemoryLogger());
        $hook = new DeferringHookClass($deferred);
        $containerB = self::locator([HookRegistrar::class => new HookRegistrar([], self::locator([DeferringHookClass::class => $hook])), AfterResponse::class => $deferred]);
        SiteKernels::pretend(static function () use (&$now): int {
            return $now;
        }, static fn(int $site): ?ContainerInterface => $site === 2 ? $containerB : null);
        $hook->onSite = static fn(): ?ContainerInterface => SiteKernels::current();

        $boot->call(DeferringHookClass::class, 'changed', [5]);

        self::assertSame([[5, false]], $hook->deferredRan, 'run before the step ended, without waiting for a response it never sees');
        self::assertSame($containerB, $hook->ranOn, 'on the site\'s own container');
    }

    public function testAHookPassedOverOnASiteBehindItsUpgradeIsLoggedOnce(): void
    {
        $now = 1;
        $logger = new MemoryLogger();
        SiteKernels::pretend(static function () use (&$now): int {
            return $now;
        }, null);
        $boot = new HookRegistrar([], self::locator([LoggerInterface::class => $logger]));
        SiteKernels::pretend(static function () use (&$now): int {
            return $now;
        }, static fn(int $site): string => $site === 77 ? SiteKernels::BEHIND : SiteKernels::OFF);

        $now = 77;
        self::assertSame('as given', $boot->call(SiteHookClass::class, 'title', ['as given'], filter: true));
        $boot->call(SiteHookClass::class, 'changed', [1]);
        $now = 78;
        $boot->call(SiteHookClass::class, 'changed', [1]);

        self::assertSame(['warning'], array_column($logger->records, 'level'), 'a site behind, once; a site without the shop is no news');
    }

    public function testOnlyTheKernelsUsedLastAreKept(): void
    {
        $kept = [];
        foreach ([1, 2, 3, 1, 4, 5] as $site) {
            $kept = SiteKernels::keep($kept, $site, "kernel {$site}", 3);
        }

        self::assertSame([1 => 'kernel 1', 4 => 'kernel 4', 5 => 'kernel 5'], $kept, 'site 1 was used again after 2 and 3');
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

/**
 * A hook class that defers work to the end of the request, as one recording price changes does.
 */
final class DeferringHookClass
{
    /** @var list<array{int, bool}> what the deferred work saw: the argument, and whether the work detached */
    public array $deferredRan = [];
    public ?ContainerInterface $ranOn = null;
    /** @var (\Closure(): ?ContainerInterface)|null */
    public ?\Closure $onSite = null;

    public function __construct(private readonly ShutdownWork $deferred) {}

    public function changed(int $id): void
    {
        $this->deferred->defer('changed', function () use ($id): void {
            $this->deferredRan[] = [$id, $this->deferred->detaches()];
            $this->ranOn = $this->onSite === null ? null : ($this->onSite)();
        });
    }
}
