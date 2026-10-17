<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Admin\AdminPageRegistrar;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSettingsRepository;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Security\Capability;
use PHPUnit\Framework\TestCase;

/**
 * The settings routes through the real WordPress REST server: registration, permission callback,
 * HTTP kernel, middleware, serializer and option storage.
 */
final class SettingsRestTest extends TestCase
{
    private const string ROUTE = '/orillacart/v1/settings';
    private const array VALID = ['currency' => 'USD', 'price_decimals' => 3, 'weight_unit' => 'lb', 'dimension_unit' => 'in'];

    /** @var list<array<array-key, mixed>> arguments of each orillacart_settings_changed action */
    private array $announced = [];

    private \Closure $listener;

    public static function setUpBeforeClass(): void
    {
        // The plugin was activated before this code existed; run activation so roles get the shop capabilities.
        $lifecycle = new Kernel(new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))
            ->container()->get(PluginLifecycle::class);
        self::assertInstanceOf(PluginLifecycle::class, $lifecycle);
        $lifecycle->activate();
    }

    protected function setUp(): void
    {
        delete_option(OptionSettingsRepository::OPTION);
        wp_set_current_user(0);
        $this->listener = function (mixed ...$arguments): void {
            $this->announced[] = $arguments;
        };
        add_action('orillacart_settings_changed', $this->listener, 10, 2);
    }

    protected function tearDown(): void
    {
        remove_action('orillacart_settings_changed', $this->listener, 10);
        delete_option(OptionSettingsRepository::OPTION);
        wp_set_current_user(0);
    }

    public function testAdministratorsHoldTheShopCapability(): void
    {
        $administrator = get_role('administrator');
        self::assertNotNull($administrator);
        self::assertTrue($administrator->has_cap(Capability::MANAGE_SHOP));
    }

    public function testWordPressItselfRefusesVisitorsBeforeThePluginRuns(): void
    {
        $endpoints = rest_get_server()->get_routes(RouteCollection::REST_NAMESPACE)[self::ROUTE] ?? null;
        self::assertIsArray($endpoints);
        self::assertNotEmpty($endpoints);

        foreach ($endpoints as $endpoint) {
            self::assertIsArray($endpoint);
            self::assertIsCallable($endpoint['permission_callback'] ?? null);
            $result = ($endpoint['permission_callback'])(new \WP_REST_Request('GET', self::ROUTE));
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame(['status' => 401], $result->get_error_data());
        }
    }

    public function testVisitorsGet401(): void
    {
        $response = $this->request('GET');

        self::assertSame(401, $response->get_status());
        self::assertSame('orillacart_unauthorized', $this->data($response)['code']);
    }

    public function testUsersWithoutTheCapabilityGet403(): void
    {
        wp_set_current_user($this->user('editor'));

        self::assertSame(403, $this->request('GET')->get_status());
        self::assertSame(403, $this->request('PUT', self::VALID, nonce: true)->get_status());
        self::assertFalse(get_option(OptionSettingsRepository::OPTION));
    }

    public function testAdministratorsReadTheDefaults(): void
    {
        wp_set_current_user($this->user('administrator'));

        $response = $this->request('GET');

        self::assertSame(200, $response->get_status());
        self::assertSame(
            ['currency' => 'EUR', 'price_decimals' => 2, 'weight_unit' => 'kg', 'dimension_unit' => 'cm', 'weight_units' => ['kg', 'g', 'lb', 'oz'], 'dimension_units' => ['cm', 'm', 'mm', 'in']],
            $this->data($response),
        );
    }

    public function testSavingNeedsAValidNonce(): void
    {
        wp_set_current_user($this->user('administrator'));

        $missing = $this->request('PUT', self::VALID);
        self::assertSame(403, $missing->get_status());
        self::assertSame('orillacart_invalid_nonce', $this->data($missing)['code']);

        self::assertSame(403, $this->request('PUT', self::VALID, nonce: 'forged')->get_status());
        self::assertFalse(get_option(OptionSettingsRepository::OPTION));
    }

    public function testAdministratorsSaveAndWordPressHearsAboutIt(): void
    {
        wp_set_current_user($this->user('administrator'));

        $response = $this->request('PUT', self::VALID, nonce: true);

        self::assertSame(200, $response->get_status());
        self::assertSame('USD', $this->data($response)['currency']);
        self::assertSame(self::VALID, get_option(OptionSettingsRepository::OPTION));
        self::assertSame([[self::VALID, ['currency' => 'EUR', 'price_decimals' => 2, 'weight_unit' => 'kg', 'dimension_unit' => 'cm']]], $this->announced);
        self::assertSame('USD', $this->data($this->request('GET'))['currency']);
    }

    public function testInvalidValuesAreRefusedPerField(): void
    {
        wp_set_current_user($this->user('administrator'));

        $response = $this->request('PUT', ['currency' => 'dollars', 'price_decimals' => 9, 'weight_unit' => 'stone', 'dimension_unit' => 'in'], nonce: true);

        self::assertSame(422, $response->get_status());
        self::assertSame(['currency', 'price_decimals', 'weight_unit'], array_column($this->violations($response), 'field'));
        self::assertFalse(get_option(OptionSettingsRepository::OPTION));
    }

    public function testUnexpectedFieldsAreRefused(): void
    {
        wp_set_current_user($this->user('administrator'));

        $response = $this->request('PUT', self::VALID + ['is_admin' => true], nonce: true);

        self::assertSame(422, $response->get_status());
        self::assertSame([['field' => 'is_admin', 'message' => 'This field is not expected.']], $this->violations($response));
        self::assertFalse(get_option(OptionSettingsRepository::OPTION));
    }

    public function testTheShopMenuShowsTheSettingsScreen(): void
    {
        global $submenu;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        wp_set_current_user($this->user('administrator'));

        do_action('admin_menu');

        self::assertIsArray($submenu);
        $items = $submenu[AdminPageRegistrar::MENU_SLUG] ?? [];
        self::assertIsArray($items);
        self::assertContains('Settings', array_column($items, 0));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, array $body = [], bool|string $nonce = false): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, self::ROUTE);
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
    private function data(\WP_REST_Response $response): array
    {
        $data = $response->get_data();
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function violations(\WP_REST_Response $response): array
    {
        $data = $this->data($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data['violations']);

        /** @var list<array{field: string, message: string}> */
        return $data['violations'];
    }

    private function user(string $role): int
    {
        $existing = get_user_by('login', 'orillacart_' . $role);
        if ($existing instanceof \WP_User) {
            return $existing->ID;
        }
        $id = wp_insert_user(['user_login' => 'orillacart_' . $role, 'user_pass' => wp_generate_password(), 'role' => $role]);
        self::assertIsInt($id);

        return $id;
    }
}
