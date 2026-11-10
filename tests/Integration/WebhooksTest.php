<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Jobs\Application\JobRunner;

/**
 * Webhooks in a real WordPress: a change the shop announces reaches the webhooks that listen, through the
 * background jobs, signed; what WordPress would send is caught instead.
 */
final class WebhooksTest extends RestTestCase
{
    /** @var list<array{string, array<array-key, mixed>}> */
    private array $sent = [];
    private \Closure $catch;
    private ?int $product = null;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        foreach (['webhook_deliveries', 'webhooks'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_jobs WHERE queue = 'webhooks'");
        $this->catch = function (mixed $answer, array $args, string $url): array {
            $this->sent[] = [$url, $args];

            return ['headers' => [], 'body' => 'ok', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $this->catch, 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', $this->catch);
        if ($this->product !== null) {
            wp_delete_post($this->product, true);
        }
        parent::tearDown();
    }

    public function testAProductChangeReachesTheWebhooksThatListenSigned(): void
    {
        $this->actAs('administrator');
        $topics = $this->data($this->request('GET', '/webhooks'))['topics'];
        self::assertIsArray($topics);
        self::assertArrayHasKey('order.placed', $topics);
        $hook = $this->data($this->request('POST', '/webhooks', ['name' => 'ERP', 'url' => 'https://erp.example.com/hook', 'topics' => ['product.changed']], nonce: true));
        self::assertIsString($hook['secret']);
        self::assertSame(422, $this->request('POST', '/webhooks', ['name' => 'Bad', 'url' => 'https://erp.example.com/hook', 'topics' => ['nothing.here']], nonce: true)->get_status());

        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Brass lamp', 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->product = $id;
        self::assertSame(200, $this->request('PUT', "/products/{$id}", ['price' => '40', 'stock_mode' => 'always_available'], nonce: true)->get_status());
        $this->work();

        $deliveries = array_values(array_filter($this->sent, static fn(array $sent): bool => $sent[0] === 'https://erp.example.com/hook'));
        self::assertNotEmpty($deliveries);
        [, $args] = $deliveries[0];
        self::assertIsString($args['body']);
        self::assertIsArray($args['headers']);
        $event = json_decode($args['body'], true);
        self::assertIsArray($event);
        self::assertSame('product.changed', $event['topic']);
        self::assertSame($id, self::at($event, 'data', 'product', 'id'));
        self::assertIsString($args['headers']['X-OrillaCart-Signature']);
        self::assertSame(1, preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $args['headers']['X-OrillaCart-Signature'], $signature));
        self::assertSame(hash_hmac('sha256', $signature[1] . '.' . $args['body'], $hook['secret']), $signature[2]);

        self::assertIsInt($hook['id']);
        $tries = $this->data($this->request('GET', "/webhooks/{$hook['id']}/deliveries"));
        self::assertIsArray($tries[0]);
        self::assertSame([200, true], [$tries[0]['status'], $tries[0]['succeeded']]);

        self::assertSame(202, $this->request('POST', "/webhooks/{$hook['id']}/ping", nonce: true)->get_status());
        $this->work();
        $last = end($this->sent);
        self::assertIsArray($last);
        self::assertIsArray($last[1]['headers']);
        self::assertSame('webhook.ping', $last[1]['headers']['X-OrillaCart-Topic']);
    }

    public function testAWebhookStopsWhenTheManagerItWasSavedByIsDemotedOrDeleted(): void
    {
        $this->expectOutputRegex('/WARNING: Webhook "ERP" \(#\d+\) was switched off: the shop manager it was saved by no longer manages the shop\./');
        $this->actAs('administrator');
        $manager = wp_insert_user(['user_login' => 'orillacart_webhook_owner', 'user_pass' => wp_generate_password(), 'role' => 'administrator']);
        self::assertIsInt($manager);
        try {
            wp_set_current_user($manager);
            $hook = $this->data($this->request('POST', '/webhooks', ['name' => 'ERP', 'url' => 'https://erp.example.com/hook', 'topics' => ['product.changed']], nonce: true));
            self::assertSame($manager, $hook['owner_id']);
            self::assertIsInt($hook['id']);

            $user = get_user_by('id', $manager);
            self::assertInstanceOf(\WP_User::class, $user);
            $user->set_role('subscriber');
            $this->actAs('administrator');
            $listed = $this->rows($this->data($this->request('GET', '/webhooks'))['webhooks'] ?? null);
            self::assertSame([false, 'owner_left'], [$listed[0]['active'] ?? null, $listed[0]['off_reason'] ?? null]);

            $on = $this->data($this->request('PUT', "/webhooks/{$hook['id']}", ['name' => 'ERP', 'url' => 'https://erp.example.com/hook', 'topics' => ['product.changed'], 'active' => true], nonce: true));
            self::assertSame([true, get_current_user_id()], [$on['active'], $on['owner_id']], 'the manager who switched it on answers for it');
        } finally {
            wp_delete_user($manager);
        }
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = \is_array($value) ? $value[$key] ?? null : null;
        }

        return $value;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_filter($value, \is_array(...)));
    }

    private function work(): void
    {
        $runner = self::kernel()->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        while ($runner->work(['webhooks'], 100, 'test')->count() > 0);
    }
}
