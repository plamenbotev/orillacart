<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Cart\Application\AbandonedCarts;

/**
 * Taking a cart reminder against the database: in one statement, so of two workers only one sends it.
 */
final class CartRemindersTest extends RestTestCase
{
    public function testOnlyOneTakesTheReminderAndOneLetGoIsTakenAgain(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $wpdb->insert("{$wpdb->prefix}orillacart_abandoned_carts", ['cart_id' => 999999, 'items' => 1, 'subtotal' => '40', 'currency' => 'EUR', 'abandoned_at' => gmdate('Y-m-d H:i:s')]);
        $id = (int) $wpdb->insert_id;
        try {
            $carts = self::kernel()->container()->get(AbandonedCarts::class);
            self::assertInstanceOf(AbandonedCarts::class, $carts);

            self::assertTrue($carts->claimReminder($id));
            self::assertFalse($carts->claimReminder($id), 'a second worker');
            self::assertNotNull($wpdb->get_var("SELECT reminded_at FROM {$wpdb->prefix}orillacart_abandoned_carts WHERE id = {$id}"));

            $carts->releaseReminder($id);
            self::assertNull($wpdb->get_var("SELECT reminded_at FROM {$wpdb->prefix}orillacart_abandoned_carts WHERE id = {$id}"));
            self::assertTrue($carts->claimReminder($id), 'tried again after the mail system refused it');
        } finally {
            $wpdb->delete("{$wpdb->prefix}orillacart_abandoned_carts", ['id' => $id]);
        }
    }
}
