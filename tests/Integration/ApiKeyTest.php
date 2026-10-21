<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * API keys in a real WordPress: made by a shop manager, then the request with one acts as them, within the key's
 * scope; a key the shop has not is refused, and no key manages keys.
 */
final class ApiKeyTest extends RestTestCase
{
    /** @var array<mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_api_keys");
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $this->authenticate(null);
        parent::tearDown();
    }

    public function testARequestWithAKeyActsAsWhoMadeItWithinItsScope(): void
    {
        $admin = $this->actAs('administrator');
        $made = $this->data($this->request('POST', '/api-keys', ['name' => 'Accounting', 'scope' => 'read'], nonce: true));
        self::assertIsString($made['key']);
        wp_set_current_user(0);

        self::assertSame($admin, $this->authenticate($made['key']));
        self::assertNull(apply_filters('rest_authentication_errors', null));
        self::assertSame(200, $this->request('GET', '/orders')->get_status(), 'no nonce: a key sends no cookies');
        self::assertSame('orillacart_read_only_key', $this->data($this->request('PUT', '/review-settings', ['policy' => 'off']))['code']);
        self::assertSame('orillacart_api_key_forbidden', $this->data($this->request('GET', '/api-keys'))['code'], 'keys are a logged-in manager\'s to manage');
    }

    public function testAKeyTheShopHasNotIsRefusedNotServedAsAVisitor(): void
    {
        self::assertSame(0, $this->authenticate('oc_' . str_repeat('0', 40)));
        $refused = apply_filters('rest_authentication_errors', null);
        self::assertInstanceOf(\WP_Error::class, $refused);
        self::assertSame('orillacart_invalid_api_key', $refused->get_error_code());

        self::assertSame(0, $this->authenticate('oc_' . str_repeat('0', 40), '/wp-json/wp/v2/users'));
        self::assertNotInstanceOf(\WP_Error::class, apply_filters('rest_authentication_errors', null), 'keys open the shop\'s API only: elsewhere a key is not asked about');
    }

    /** As WordPress finds the user of a request to the shop's API with the key; null for none. */
    private function authenticate(?string $key, string $uri = '/wp-json/orillacart/v1/orders'): int
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        if ($key !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
            $_SERVER['REQUEST_URI'] = $uri;
        }
        $user = apply_filters('determine_current_user', 0);
        wp_set_current_user(\is_int($user) ? $user : 0);

        return get_current_user_id();
    }
}
