<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use PHPUnit\Framework\TestCase;

/**
 * Calls the plugin's routes through the real WordPress REST server, as a visitor or as a user of a role.
 */
abstract class RestTestCase extends TestCase
{
    protected const string NAMESPACE = '/orillacart/v1';

    public static function setUpBeforeClass(): void
    {
        // The plugin may have been activated before this code existed; activation adds capabilities and tables.
        $lifecycle = self::kernel()->container()->get(PluginLifecycle::class);
        self::assertInstanceOf(PluginLifecycle::class, $lifecycle);
        $lifecycle->activate();
    }

    protected function setUp(): void
    {
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
    }

    protected static function kernel(): Kernel
    {
        return new Kernel(new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION));
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function request(string $method, string $route, array $body = [], bool|string $nonce = false): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, self::NAMESPACE . $route);
        if ($body !== []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }
        if ($nonce !== false) {
            $request->set_header('X-WP-Nonce', $nonce === true ? wp_create_nonce('wp_rest') : $nonce);
        }

        return rest_do_request($request);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function data(\WP_REST_Response $response): array
    {
        $data = $response->get_data();
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    protected function violations(\WP_REST_Response $response): array
    {
        $data = $this->data($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data['violations']);

        /** @var list<array{field: string, message: string}> */
        return $data['violations'];
    }

    protected function actAs(string $role): int
    {
        $existing = get_user_by('login', 'orillacart_' . $role);
        if ($existing instanceof \WP_User) {
            $id = $existing->ID;
        } else {
            $id = wp_insert_user(['user_login' => 'orillacart_' . $role, 'user_pass' => wp_generate_password(), 'role' => $role]);
            self::assertIsInt($id);
        }
        wp_set_current_user($id);

        return $id;
    }
}
