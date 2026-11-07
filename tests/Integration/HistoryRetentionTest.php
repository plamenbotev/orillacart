<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Settings\Infrastructure\WordPress\ForgetOldHistory;

/**
 * Design 55: the shop keeps its history (stock movements, past prices, carts left at checkout) for as long as the
 * "Keep history for" setting says, a year unless changed, and a daily task deletes the rest.
 */
final class HistoryRetentionTest extends RestTestCase
{
    public function testHistoryOlderThanTheShopKeepsItGoesDailyAndEachItemsCurrentPriceStays(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $this->actAs('administrator');
        $prices = $wpdb->prefix . 'orillacart_price_history';
        $movements = $wpdb->prefix . 'orillacart_stock_movements';
        $left = $wpdb->prefix . 'orillacart_abandoned_carts';
        $wpdb->query("DELETE FROM {$prices}");
        $at = static fn(string $ago): string => new \DateTimeImmutable($ago)->format('Y-m-d H:i:s');
        // A product priced twice: the old point goes, the current one stays. Another priced once, long ago: its
        // only point is its price now and stays whatever its age.
        foreach ([['product:1', '10.0000', $at('-400 days')], ['product:1', '12.0000', $at('-10 days')], ['product:2', '5.0000', $at('-700 days')]] as [$item, $price, $recorded]) {
            $wpdb->insert($prices, ['item' => $item, 'price' => $price, 'recorded_at' => $recorded]);
        }
        $oldMovements = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$movements} WHERE moved_at < '" . $at('-365 days') . "'");
        $oldCarts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$left} WHERE abandoned_at < '" . $at('-365 days') . "'");

        $hook = ForgetOldHistory::hook();
        \assert($hook !== '');
        self::assertTrue(has_action($hook), 'the task is scheduled');
        do_action($hook);

        self::assertSame(
            [['item' => 'product:1', 'price' => '12.0000'], ['item' => 'product:2', 'price' => '5.0000']],
            $wpdb->get_results("SELECT item, price FROM {$prices} ORDER BY item", ARRAY_A),
        );
        self::assertSame(0, $oldMovements + $oldCarts, 'nothing older than the history was there to begin with; the task ran over these tables too');
        self::assertSame('0', $wpdb->get_var("SELECT COUNT(*) FROM {$movements} WHERE moved_at < '" . $at('-365 days') . "'"));
    }
}
