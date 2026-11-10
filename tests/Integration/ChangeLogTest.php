<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;

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

    public function testAProductSavedThroughWordPresssOwnApiIsKept(): void
    {
        $admin = $this->actAs('administrator');
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Lamp', 'post_status' => 'draft']);
        self::assertIsInt($id);
        try {
            $request = new \WP_REST_Request('POST', '/wp/v2/' . ProductPostType::NAME . '/' . $id);
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode(['title' => 'Desk lamp', 'content' => str_repeat('Bright. ', 100)]));
            self::assertSame(200, rest_do_request($request)->get_status());

            $records = $this->data($this->request('GET', '/change-log?area=wp'))['records'];
            self::assertIsArray($records);
            self::assertIsArray($records[0] ?? null);
            self::assertSame([$admin, 'POST /wp/v2/' . ProductPostType::NAME . '/{id}', '/wp/v2/' . ProductPostType::NAME . '/' . $id, ['title' => 'Desk lamp', 'content' => '(800 characters)']], [$records[0]['user_id'], $records[0]['route'], $records[0]['path'], $records[0]['values']]);
        } finally {
            wp_delete_post($id, true);
        }
    }

    public function testASaveSentInTheAddressIsKeptWithWhatItSent(): void
    {
        $this->actAs('administrator');
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Lamp', 'post_status' => 'draft']);
        self::assertIsInt($id);
        try {
            $request = new \WP_REST_Request('POST', '/wp/v2/' . ProductPostType::NAME . '/' . $id);
            $request->set_query_params(['status' => 'publish', 'title' => 'Desk lamp', '_fields' => 'id']);
            self::assertSame(200, rest_do_request($request)->get_status());

            $records = $this->records('wp');
            self::assertSame(['status' => 'publish', 'title' => 'Desk lamp'], $records[0]['values'] ?? null);
        } finally {
            wp_delete_post($id, true);
        }
    }

    public function testAProductTrashedRestoredAndDeletedIsKeptHoweverItWasAskedFor(): void
    {
        $admin = $this->actAs('administrator');
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Lamp', 'post_status' => 'publish']);
        self::assertIsInt($id);
        wp_trash_post($id);
        wp_untrash_post($id);
        $request = new \WP_REST_Request('DELETE', '/wp/v2/' . ProductPostType::NAME . '/' . $id);
        $request->set_query_params(['force' => true]);
        self::assertSame(200, rest_do_request($request)->get_status());

        $records = array_reverse($this->records('products'));
        self::assertSame([
            ['POST /products/{id}/trash', "/products/{$id}/trash", []],
            ['POST /products/{id}/restore', "/products/{$id}/restore", []],
            ['DELETE /products/{id}', "/products/{$id}", ['title' => 'Lamp']],
        ], array_map(static fn(array $record): array => [$record['route'], $record['path'], $record['values']], $records));
        self::assertSame([$admin, $admin, $admin], array_column($records, 'user_id'));
    }

    public function testQuickEditAndTheClassicEditingScreenAreKept(): void
    {
        $this->actAs('administrator');
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Lamp', 'post_status' => 'draft']);
        self::assertIsInt($id);
        $post = $_POST;
        set_current_screen('edit-' . ProductPostType::NAME);
        try {
            $_POST = ['action' => 'inline-save', 'post_ID' => (string) $id, 'post_title' => 'Desk lamp', '_status' => 'publish', 'post_status' => 'publish', 'content' => 'As it was.', '_inline_edit' => 'nonce', 'screen' => 'edit-' . ProductPostType::NAME];
            wp_update_post(['ID' => $id, 'post_title' => 'Desk lamp', 'post_status' => 'publish']);
            $_POST = ['action' => 'editpost', 'post_ID' => (string) $id, 'post_title' => 'Brass lamp', 'content' => 'Warm light.', '_wpnonce' => 'nonce', 'tax_input' => ['orillacart_product_cat' => ['0', '7']]];
            wp_update_post(['ID' => $id, 'post_title' => 'Brass lamp']);
            $_POST = ['action' => 'heartbeat', 'post_ID' => (string) $id, 'post_title' => 'Autosaved'];
            wp_update_post(['ID' => $id, 'post_title' => 'Brass lamp']);
        } finally {
            $_POST = $post;
            wp_delete_post($id, true);
        }

        $records = array_values(array_filter(array_reverse($this->records('products')), static fn(array $record): bool => $record['route'] !== 'DELETE /products/{id}'));
        self::assertSame([
            ['PUT /products/{id}/quick-edit', ['post_title' => 'Desk lamp', 'post_status' => 'publish']],
            ['PUT /products/{id}/edit', ['post_title' => 'Brass lamp', 'content' => 'Warm light.', 'tax_input' => ['orillacart_product_cat' => ['0', '7']]]],
        ], array_map(static fn(array $record): array => [$record['route'], $record['values']], $records), 'not the form\'s own fields, nor a save that is neither');
    }

    /**
     * The change log's records of an area, newest first.
     *
     * @return list<array<array-key, mixed>>
     */
    private function records(string $area): array
    {
        $records = $this->data($this->request('GET', "/change-log?area={$area}&per_page=100"))['records'];
        self::assertIsArray($records);

        return array_values(array_filter($records, \is_array(...)));
    }
}
