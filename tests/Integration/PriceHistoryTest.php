<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\PriceHistory;

/**
 * The price history's queries against the database: the latest point of each item, and the points of the last
 * year with the one in effect before it.
 */
final class PriceHistoryTest extends RestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_price_history");
    }

    public function testTheLowestPriceBeforeASaleComesFromTheKeptPoints(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $at = static fn(string $ago): string => gmdate('Y-m-d H:i:s', (int) strtotime($ago));
        foreach ([['product:7', '30', '-400 days'], ['product:7', '25', '-50 days'], ['product:7', '20', '-45 days'], ['product:7', '15', '-5 days'], ['product:8', '9', '-3 days']] as [$item, $price, $ago]) {
            $wpdb->insert("{$wpdb->prefix}orillacart_price_history", ['item' => $item, 'price' => $price, 'recorded_at' => $at($ago)]);
        }
        $history = self::kernel()->container()->get(PriceHistory::class);
        self::assertInstanceOf(PriceHistory::class, $history);

        // 15 began 5 days ago; in the 30 days before it, 20 held.
        self::assertSame(['product:7' => '20'], $history->lowestBefore(['product:7', 'product:8']));

        $history->record([new ProductOffer(7, 'Lamp', 'physical', null, '15', '22', null, true, null, false, [], [], []), new ProductOffer(8, 'Mug', 'physical', null, '8', null, null, true, null, false, [], [], [])]);
        self::assertSame(6, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orillacart_price_history"), 'only the mug changed');
    }
}
