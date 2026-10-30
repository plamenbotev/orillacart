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
}
