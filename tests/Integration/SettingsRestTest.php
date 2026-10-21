<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\WordPress\Admin\AdminPageRegistrar;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSettingsRepository;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Security\Capability;

/**
 * The settings routes through the real WordPress REST server: registration, permission callback,
 * HTTP kernel, middleware, serializer and option storage.
 */
final class SettingsRestTest extends RestTestCase
{
    private const string ROUTE = '/settings';
    private const array VALID = ['currency' => 'USD', 'fewer_decimals' => 0, 'weight_unit' => 'lb', 'dimension_unit' => 'in', 'shop_country' => 'US', 'shop_state' => 'NY'];
    /** What the shop has without saying otherwise, besides the fields above. */
    private const array UNSAID = ['shop_postcode' => null, 'currency_sign' => '', 'currency_position' => 'after_space', 'catalog_only' => false, 'hide_prices' => false, 'sell_to' => [], 'show_lowest_price' => false];

    /** @var list<array<array-key, mixed>> arguments of each orillacart_settings_changed action */
    private array $announced = [];

    private \Closure $listener;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(OptionSettingsRepository::OPTION);
        $this->listener = function (mixed ...$arguments): void {
            $this->announced[] = $arguments;
        };
        add_action('orillacart_settings_changed', $this->listener, 10, 2);
    }

    protected function tearDown(): void
    {
        remove_action('orillacart_settings_changed', $this->listener, 10);
        delete_option(OptionSettingsRepository::OPTION);
        parent::tearDown();
    }

    public function testAdministratorsHoldTheShopCapability(): void
    {
        $administrator = get_role('administrator');
        self::assertNotNull($administrator);
        self::assertTrue($administrator->has_cap(Capability::MANAGE_SHOP));
    }

    public function testWordPressItselfRefusesVisitorsBeforeThePluginRuns(): void
    {
        $endpoints = rest_get_server()->get_routes(RouteCollection::REST_NAMESPACE)[self::NAMESPACE . self::ROUTE] ?? null;
        self::assertIsArray($endpoints);
        self::assertNotEmpty($endpoints);

        foreach ($endpoints as $endpoint) {
            self::assertIsArray($endpoint);
            self::assertIsCallable($endpoint['permission_callback'] ?? null);
            $result = ($endpoint['permission_callback'])(new \WP_REST_Request('GET', self::NAMESPACE . self::ROUTE));
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame(['status' => 401], $result->get_error_data());
        }
    }

    public function testVisitorsGet401(): void
    {
        $response = $this->request('GET', self::ROUTE);

        self::assertSame(401, $response->get_status());
        self::assertSame('orillacart_unauthorized', $this->data($response)['code']);
    }

    public function testUsersWithoutTheCapabilityGet403(): void
    {
        $this->actAs('editor');

        self::assertSame(403, $this->request('GET', self::ROUTE)->get_status());
        self::assertSame(403, $this->request('PUT', self::ROUTE, self::VALID, nonce: true)->get_status());
        self::assertFalse(get_option(OptionSettingsRepository::OPTION));
    }

    public function testAdministratorsReadTheDefaults(): void
    {
        $this->actAs('administrator');

        $response = $this->request('GET', self::ROUTE);

        self::assertSame(200, $response->get_status());
        self::assertSame(
            ['currency' => 'EUR', 'price_decimals' => 2, 'weight_unit' => 'kg', 'dimension_unit' => 'cm', 'shop_country' => null, 'shop_state' => null, 'weight_units' => ['kg', 'g', 'lb', 'oz'], 'dimension_units' => ['cm', 'm', 'mm', 'in']]
                + array_diff_key(self::UNSAID, ['show_lowest_price' => true]) + ['currency_positions' => ['before', 'before_space', 'after', 'after_space'], 'show_lowest_price' => false, 'fewer_decimals' => null, 'currency_decimals' => 2, 'remove_data_on_uninstall' => false, 'low_stock_threshold' => 2, 'unpaid_hold_days' => null, 'return_days' => null, 'minimum_order' => null],
            $this->data($response),
        );
    }

    public function testSavingNeedsAValidNonce(): void
    {
        $this->actAs('administrator');

        $missing = $this->request('PUT', self::ROUTE, self::VALID);
        self::assertSame(403, $missing->get_status());
        self::assertSame('orillacart_invalid_nonce', $this->data($missing)['code']);

        self::assertSame(403, $this->request('PUT', self::ROUTE, self::VALID, nonce: 'forged')->get_status());
        self::assertFalse(get_option(OptionSettingsRepository::OPTION));
    }

    public function testAdministratorsSaveAndWordPressHearsAboutIt(): void
    {
        $this->actAs('administrator');

        $response = $this->request('PUT', self::ROUTE, self::VALID, nonce: true);

        self::assertSame(200, $response->get_status());
        self::assertSame('USD', $this->data($response)['currency']);
        self::assertSame(self::VALID + self::UNSAID + ['remove_data_on_uninstall' => false, 'low_stock_threshold' => 2, 'unpaid_hold_days' => null, 'return_days' => null, 'minimum_order' => null], get_option(OptionSettingsRepository::OPTION));
        self::assertSame([[['currency' => 'USD', 'price_decimals' => 0, 'weight_unit' => 'lb', 'dimension_unit' => 'in', 'shop_country' => 'US', 'shop_state' => 'NY'] + self::UNSAID, ['currency' => 'EUR', 'price_decimals' => 2, 'weight_unit' => 'kg', 'dimension_unit' => 'cm', 'shop_country' => null, 'shop_state' => null] + self::UNSAID]], $this->announced);
        self::assertSame('USD', $this->data($this->request('GET', self::ROUTE))['currency']);
    }

    public function testInvalidValuesAreRefusedPerField(): void
    {
        $this->actAs('administrator');

        $response = $this->request('PUT', self::ROUTE, ['currency' => 'dollars', 'fewer_decimals' => -1, 'weight_unit' => 'stone', 'dimension_unit' => 'in'], nonce: true);

        self::assertSame(422, $response->get_status());
        self::assertSame(['currency', 'fewer_decimals', 'weight_unit'], array_column($this->violations($response), 'field'));
        self::assertFalse(get_option(OptionSettingsRepository::OPTION));
    }

    public function testTheShopAddressMustExist(): void
    {
        $this->actAs('administrator');

        $response = $this->request('PUT', self::ROUTE, ['shop_state' => 'ON'] + self::VALID, nonce: true);

        self::assertSame(422, $response->get_status());
        self::assertSame('orillacart_unknown_state', $this->data($response)['code']);
        self::assertFalse(get_option(OptionSettingsRepository::OPTION));
    }

    public function testUnexpectedFieldsAreRefused(): void
    {
        $this->actAs('administrator');

        $response = $this->request('PUT', self::ROUTE, self::VALID + ['is_admin' => true], nonce: true);

        self::assertSame(422, $response->get_status());
        self::assertSame([['field' => 'is_admin', 'message' => 'This field is not expected.']], $this->violations($response));
        self::assertFalse(get_option(OptionSettingsRepository::OPTION));
    }

    public function testTheShopMenuShowsTheSettingsScreen(): void
    {
        global $submenu;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $this->actAs('administrator');

        do_action('admin_menu');

        self::assertIsArray($submenu);
        $items = $submenu[AdminPageRegistrar::MENU_SLUG] ?? [];
        self::assertIsArray($items);
        self::assertContains('Settings', array_column($items, 0));
    }
}
