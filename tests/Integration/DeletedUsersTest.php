<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * A WordPress user deleted for good takes their API keys with them; their newsletter sign-up and their uses of
 * coupons stay with their email, and no longer name the account.
 */
final class DeletedUsersTest extends RestTestCase
{
    private const array TABLES = ['api_keys', 'newsletter_subscribers', 'coupon_redemptions'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testADeletedUsersKeysGoAndTheirRecordsKeepTheirEmail(): void
    {
        $user = wp_insert_user(['user_login' => 'orillacart_leaving', 'user_email' => 'leaving@example.com', 'user_pass' => wp_generate_password(), 'role' => 'administrator']);
        self::assertIsInt($user);
        wp_set_current_user($user);
        self::assertSame(201, $this->request('POST', '/api-keys', ['name' => 'Accounting', 'scope' => 'read'], nonce: true)->get_status());
        $wpdb = $this->wpdb();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'orillacart_newsletter_subscribers', ['email' => 'leaving@example.com', 'first_name' => 'Lee', 'last_name' => 'Ving', 'consent' => 'Yes, send me news.', 'source' => 'account', 'subscribed_at' => $now, 'customer_id' => $user]);
        $wpdb->insert($wpdb->prefix . 'orillacart_coupon_redemptions', ['coupon_id' => 1, 'order_id' => 1, 'customer_id' => $user, 'email' => 'leaving@example.com', 'amount' => '5', 'redeemed_at' => $now]);

        $keys = static fn(): mixed => $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE user_id = %d', $wpdb->prefix . 'orillacart_api_keys', $user));
        self::assertSame('1', $keys());

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user);

        self::assertSame('0', $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE user_id = %d', $wpdb->prefix . 'orillacart_api_keys', $user)), 'its keys went');
        self::assertSame([null], $wpdb->get_col($wpdb->prepare('SELECT customer_id FROM %i WHERE email = %s', $wpdb->prefix . 'orillacart_newsletter_subscribers', 'leaving@example.com')), 'still signed up, by email');
        self::assertSame([null], $wpdb->get_col($wpdb->prepare('SELECT customer_id FROM %i WHERE email = %s', $wpdb->prefix . 'orillacart_coupon_redemptions', 'leaving@example.com')), 'the use still counts for the email');
    }

    private function wpdb(): \wpdb
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);

        return $wpdb;
    }

    private function clean(): void
    {
        $wpdb = $this->wpdb();
        foreach (self::TABLES as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
        $user = get_user_by('login', 'orillacart_leaving');
        if ($user instanceof \WP_User) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user->ID);
        }
    }
}
