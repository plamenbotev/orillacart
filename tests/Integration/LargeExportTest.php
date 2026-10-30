<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Exchange\Application\CsvFile;
use OrillaCart\Exchange\Application\Dto\ChooseColumns;
use OrillaCart\Exchange\Application\OrdersExport;
use OrillaCart\Exchange\Infrastructure\WordPress\OptionExportChoices;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Domain\OrderSearch;

/**
 * An export of more orders than one batch on MySQL: every order once, in the list's order, whether by number or
 * by total, and nothing of a batch kept in memory once it is written.
 */
final class LargeExportTest extends RestTestCase
{
    private const int ORDERS = 230;

    private int $product = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        delete_option(OptionExportChoices::OPTION);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[CartCookie::NAME]);
        wp_delete_post($this->product, true);
        $this->clean();
        delete_option(OptionExportChoices::OPTION);
        parent::tearDown();
    }

    public function testEveryOrderIsWrittenOnceInTheListsOrderAndEachBatchIsForgotten(): void
    {
        $this->actAs('administrator');
        $this->request('PUT', '/payment-methods/bank_transfer', ['name' => 'Bank transfer', 'active' => true], nonce: true);
        $first = $this->order();
        $numbers = [$first, ...$this->copies($first, self::ORDERS - 1)];
        $export = self::kernel()->container()->get(OrdersExport::class);
        self::assertInstanceOf(OrdersExport::class, $export);
        $export->choose(new ChooseColumns(['number', 'items']));
        $entities = self::entityManager($export);

        $kept = [];
        $newestFirst = $this->numbers($export, new OrderQuery(), static function () use ($entities, &$kept): void {
            $kept[] = $entities->getUnitOfWork()->size();
        });

        rsort($numbers);
        self::assertSame($numbers, $newestFirst, 'each order once, newest first');
        self::assertSame([0, 0, 0], $kept, 'three batches, each forgotten once written');

        // Every total the same: the number tells them apart, so a batch neither repeats nor skips one.
        sort($numbers);
        self::assertSame($numbers, $this->numbers($export, new OrderQuery(order: 'asc', orderBy: OrderSearch::BY_TOTAL)));
    }

    /**
     * @param (\Closure(): void)|null $between
     *
     * @return list<int>
     */
    private function numbers(OrdersExport $export, OrderQuery $query, ?\Closure $between = null): array
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $export->export($query, new CsvFile($stream), $between);
        rewind($stream);
        $lines = \array_slice(explode("\n", trim(substr((string) stream_get_contents($stream), 3))), 1);
        foreach ($lines as $line) {
            self::assertStringEndsWith(',"1 × Lamp"', $line, 'its items read with it');
        }

        return array_map(static fn(string $line): int => (int) explode(',', $line)[0], $lines);
    }

    /**
     * Copies of the order and its lines, made in the database: placing each through checkout takes too long.
     *
     * @return list<int>
     */
    private function copies(int $order, int $count): array
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $columns = static function (string $table, array $except) use ($wpdb): string {
            $names = array_filter(array_map(strval(...), $wpdb->get_col("SHOW COLUMNS FROM {$table}")), static fn(string $column): bool => !\in_array($column, $except, true));

            return implode(', ', array_map(static fn(string $column): string => "`{$column}`", $names));
        };
        $orders = "{$wpdb->prefix}orillacart_orders";
        $lines = "{$wpdb->prefix}orillacart_order_lines";
        $orderColumns = $columns($orders, ['id']);
        $lineColumns = $columns($lines, ['id', 'order_id']);
        $copies = [];
        for ($i = 0; $i < $count; ++$i) {
            $wpdb->query("INSERT INTO {$orders} ({$orderColumns}) SELECT {$orderColumns} FROM {$orders} WHERE id = {$order}");
            $copy = (int) $wpdb->insert_id;
            $wpdb->query("INSERT INTO {$lines} ({$lineColumns}, order_id) SELECT {$lineColumns}, {$copy} FROM {$lines} WHERE order_id = {$order}");
            $copies[] = $copy;
        }

        return $copies;
    }

    private function order(): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Lamp', 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->product = $id;
        $saved = $this->request('PUT', "/products/{$id}", ['price' => '30', 'type' => 'virtual'], nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
        wp_set_current_user(0);
        unset($_COOKIE[CartCookie::NAME]);
        $added = $this->request('POST', '/cart/items', ['product_id' => $id, 'quantity' => 1], nonce: true);
        $cookie = $added->get_headers()['Set-Cookie'] ?? null;
        if (\is_string($cookie)) {
            $_COOKIE[CartCookie::NAME] = substr($cookie, \strlen(CartCookie::NAME) + 1, 64);
        }
        $placed = $this->request('POST', '/checkout', [
            'email' => 'ada@example.com',
            'billing' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => '1 Vitosha Blvd', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG', 'phone' => '+359 2 000 0000'],
            'payment' => 'bank_transfer',
        ], nonce: true);
        self::assertSame(201, $placed->get_status(), (string) wp_json_encode($placed->get_data()));
        $number = $this->data($placed)['number'];
        self::assertIsInt($number);
        $this->actAs('administrator');

        return $number;
    }

    /** The entity manager the export forgets its batches in. */
    private static function entityManager(OrdersExport $export): EntityManagerInterface
    {
        $entities = new \ReflectionProperty($export, 'entities')->getValue($export);
        self::assertIsObject($entities);
        $manager = new \ReflectionProperty($entities, 'entityManager')->getValue($entities);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function clean(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        foreach (['invoices', 'download_grants', 'order_notes', 'refunds', 'payments', 'order_lines', 'orders', 'cart_lines', 'carts', 'stock_levels', 'variations', 'products'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
    }
}
