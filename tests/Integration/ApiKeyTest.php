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
        unset($_GET['rest_route'], $_POST['rest_route']);
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

    public function testAKeyOpensNoOtherRouteWhateverItsAddressSays(): void
    {
        $admin = $this->actAs('administrator');
        $made = $this->data($this->request('POST', '/api-keys', ['name' => 'ERP', 'scope' => 'read_write'], nonce: true));
        self::assertIsString($made['key']);
        wp_set_current_user(0);

        self::assertSame(0, $this->authenticate($made['key'], '/wp-json/wp/v2/users?x=/wp-json/orillacart/v1/'), 'the shop\'s path in the query string is not the shop\'s API');
        self::assertSame(0, $this->authenticate($made['key'], '/wp-json/wp/v2/users#/wp-json/orillacart/v1/'));
        $_GET['rest_route'] = '/wp/v2/users';
        self::assertSame(0, $this->authenticate($made['key']), 'a rest_route parameter wins over the path, as WordPress has it');
        $_GET['rest_route'] = '/orillacart/v1/../wp/v2/users';
        self::assertSame(0, $this->authenticate($made['key'], '/'));
        unset($_GET['rest_route']);

        self::assertSame($admin, $this->authenticate($made['key']));
        $users = rest_do_request(new \WP_REST_Request('POST', '/wp/v2/users'));
        self::assertSame(403, $users->get_status(), 'what WordPress dispatches is checked again');
        self::assertSame('orillacart_api_key_forbidden', $this->data($users)['code']);
        self::assertSame(200, $this->request('GET', '/orders')->get_status());
        self::assertSame('orillacart_api_key_forbidden', $this->data($this->request('GET', '/webhooks'))['code'], 'webhooks are a logged-in manager\'s: their secrets sign what the shop sends');
    }

    public function testAWebhooksSecretIsShownOnceAndKeptSealed(): void
    {
        $this->actAs('administrator');
        $made = $this->data($this->request('POST', '/webhooks', ['name' => 'ERP', 'url' => 'https://erp.example.com/hook', 'topics' => ['order.placed']], nonce: true));
        self::assertIsString($made['secret']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $made['secret']);
        $listed = $this->data($this->request('GET', '/webhooks'));
        self::assertIsArray($listed['webhooks']);
        foreach ($listed['webhooks'] as $webhook) {
            self::assertIsArray($webhook);
            self::assertNull($webhook['secret'], 'shown once, when made');
        }
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $stored = $wpdb->get_var($wpdb->prepare('SELECT secret FROM %i WHERE id = %d', $wpdb->prefix . 'orillacart_webhooks', $made['id']));
        self::assertIsString($stored);
        self::assertStringStartsWith('orillacart:sealed:1:', $stored, 'a copy of the database alone does not give it away');
        self::assertStringNotContainsString($made['secret'], $stored);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_webhooks");
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
