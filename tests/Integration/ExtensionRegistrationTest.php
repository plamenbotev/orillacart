<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Extension\Registry;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Platform\WordPress\Extensions\WordPressExtensions;
use OrillaCart\Tests\Fixtures\Extension\TestShipMethod;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;
use PHPUnit\Framework\TestCase;

/**
 * Add-ons registering on the real orillacart_extensions action: one that throws is logged, and the ones after it
 * still register; one hooked after the action ran is warned of.
 */
final class ExtensionRegistrationTest extends TestCase
{
    public function testAnAddOnThatThrowsLeavesTheOthersRegistered(): void
    {
        global $wp_filter;
        self::assertIsArray($wp_filter);
        $hook = $wp_filter[WordPressExtensions::ACTION] ?? null;
        // A copy: adding to the action changes the hook in place.
        $before = $hook instanceof \WP_Hook ? clone $hook : null;
        $logger = new MemoryLogger();
        $registry = new WordPressExtensions([], $logger, static fn() => throw new \LogicException('not read'), static fn() => throw new \LogicException('not read'), static fn() => throw new \LogicException('not read'));
        try {
            add_action(WordPressExtensions::ACTION, static function (): void {
                throw new \RuntimeException('Broken add-on');
            });
            add_action(WordPressExtensions::ACTION, static function (Registry $registry): void {
                $registry->shippingMethod(new TestShipMethod());
            }, 20);

            $methods = $registry->shippingMethods();
        } finally {
            if ($before === null) {
                unset($wp_filter[WordPressExtensions::ACTION]);
            } else {
                $wp_filter[WordPressExtensions::ACTION] = $before;
            }
        }

        self::assertSame([TestShipMethod::ID], array_map(static fn(ShippingMethod $method): string => $method->id(), $methods));
        self::assertSame('error', $logger->records[0]['level'] ?? null);
        $callback = $logger->records[0]['context']['callback'] ?? null;
        self::assertIsString($callback);
        self::assertStringContainsString('a closure in ' . __FILE__, $callback);
    }

    public function testAnAddOnHookedAfterTheActionRanIsWarnedOfOnce(): void
    {
        global $wp_filter;
        self::assertIsArray($wp_filter);
        $hook = $wp_filter[WordPressExtensions::ACTION] ?? null;
        $before = $hook instanceof \WP_Hook ? clone $hook : null;
        $logger = new MemoryLogger();
        $registry = new WordPressExtensions([], $logger, static fn() => throw new \LogicException('not read'), static fn() => throw new \LogicException('not read'), static fn() => throw new \LogicException('not read'));
        try {
            $first = $registry->shippingMethods();
            $inTime = $logger->records;
            add_action(WordPressExtensions::ACTION, static function (Registry $registry): void {
                $registry->shippingMethod(new TestShipMethod());
            });

            $methods = $registry->shippingMethods();
            $registry->paymentGateways();
        } finally {
            if ($before === null) {
                unset($wp_filter[WordPressExtensions::ACTION]);
            } else {
                $wp_filter[WordPressExtensions::ACTION] = $before;
            }
        }

        self::assertSame([], $inTime, 'the add-ons hooked in time are not warned of');
        self::assertSame($first, $methods, 'the action does not run again');
        self::assertSame(['warning'], array_column($logger->records, 'level'));
        $callback = $logger->records[0]['context']['callback'] ?? null;
        self::assertIsString($callback);
        self::assertStringContainsString('a closure in ' . __FILE__, $callback);
    }
}
