<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * The change log in a real WordPress: a shop manager's changes through the shop's API, secrets blanked out,
 * and nothing of what only reads, what failed, or what visitors do.
 */
final class ChangeLogTest extends RestTestCase
{
    private mixed $stored;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stored = get_option('orillacart_reviews');
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_change_log");
    }

    protected function tearDown(): void
    {
        $this->stored === false ? delete_option('orillacart_reviews') : update_option('orillacart_reviews', $this->stored);
        parent::tearDown();
    }

    public function testAShopManagersChangesAreKeptWithWhoAndWhat(): void
    {
        $admin = $this->actAs('administrator');
        self::assertSame(200, $this->request('PUT', '/review-settings', ['policy' => 'buyers'], nonce: true)->get_status());
        self::assertSame(422, $this->request('PUT', '/review-settings', ['policy' => 'nobody'], nonce: true)->get_status(), 'refused');
        $this->request('GET', '/review-settings');
        wp_set_current_user(0);
        $this->request('POST', '/cart/items', ['product_id' => 999999, 'quantity' => 1], nonce: true);

        $this->actAs('administrator');
        $log = $this->data($this->request('GET', '/change-log'));

        self::assertSame(1, $log['total'], 'only the change made');
        self::assertIsArray($log['records']);
        self::assertIsArray($log['records'][0]);
        $record = $log['records'][0];
        self::assertSame([$admin, 'review-settings', 'PUT /review-settings', ['policy' => 'buyers']], [$record['user_id'], $record['area'], $record['route'], $record['values']]);
        self::assertSame(['review-settings'], $log['areas']);
        self::assertSame(0, $this->data($this->request('GET', '/change-log?area=payment-methods'))['total']);
    }
}
