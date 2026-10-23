<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Reports\Domain\OrderFacts;
use OrillaCart\Reports\Domain\SoldOrder;
use OrillaCart\Reports\Infrastructure\Doctrine\DbalReportStore;
use OrillaCart\Reports\Infrastructure\Doctrine\ReportTables;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSettingsRepository;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\Dto\TaxQuery;
use OrillaCart\Tax\Application\TaxCalculator;
use OrillaCart\Tax\Infrastructure\WordPress\OptionNexusThresholdsRepository;
use OrillaCart\Tax\Infrastructure\WordPress\OptionTaxSettingsRepository;

/**
 * Tax groups, rates and settings through the REST routes, stored by Doctrine in the plugin's tables.
 */
final class TaxRestTest extends RestTestCase
{
    /** @var list<array<array-key, mixed>> arguments of each orillacart_tax_rates_changed action */
    private array $announced = [];

    private \Closure $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->listener = function (mixed ...$arguments): void {
            $this->announced[] = $arguments;
        };
        add_action('orillacart_tax_rates_changed', $this->listener);
    }

    protected function tearDown(): void
    {
        remove_action('orillacart_tax_rates_changed', $this->listener);
        $this->clean();
        parent::tearDown();
    }

    public function testOnlyShopManagersReachTheTaxRoutes(): void
    {
        $routes = [['GET', '/tax/groups'], ['POST', '/tax/groups'], ['GET', '/tax/groups/1'], ['PUT', '/tax/groups/1'], ['DELETE', '/tax/groups/1'],
            ['POST', '/tax/groups/1/rates'], ['PUT', '/tax/groups/1/rates/1'], ['DELETE', '/tax/groups/1/rates/1'], ['GET', '/tax/settings'], ['PUT', '/tax/settings'],
            ['GET', '/tax/nexus-thresholds'], ['PUT', '/tax/nexus-thresholds'], ['GET', '/reports/nexus']];

        foreach ($routes as [$method, $route]) {
            self::assertSame(401, $this->request($method, $route)->get_status(), "visitor: {$method} {$route}");
        }
        $this->actAs('editor');
        foreach ($routes as [$method, $route]) {
            self::assertSame(403, $this->request($method, $route, nonce: true)->get_status(), "editor: {$method} {$route}");
        }
    }

    public function testAShopManagerSetsUpTaxesAndTheCalculatorUsesThem(): void
    {
        $this->actAs('administrator');
        update_option(OptionSettingsRepository::OPTION, ['currency' => 'USD', 'fewer_decimals' => null, 'weight_unit' => 'lb', 'dimension_unit' => 'in', 'shop_country' => 'US', 'shop_state' => 'NY']);

        $created = $this->request('POST', '/tax/groups', ['name' => 'Standard'], nonce: true);
        self::assertSame(201, $created->get_status());
        $group = $this->id($created);

        $federal = $this->id($this->request('POST', "/tax/groups/{$group}/rates", ['country' => 'US', 'state' => null, 'percent' => '2', 'name' => 'Federal', 'priority' => 2], nonce: true));
        $california = $this->request('POST', "/tax/groups/{$group}/rates", ['country' => 'US', 'state' => 'CA', 'percent' => '7.25', 'name' => 'California', 'priority' => 1], nonce: true);
        self::assertSame(201, $california->get_status());
        self::assertSame(['country' => 'US', 'state' => 'CA', 'percent' => '7.25', 'name' => 'California', 'priority' => 1, 'postcodes' => [], 'compound' => false, 'code' => null, 'jurisdiction' => ['country' => 'US', 'region' => 'CA', 'level' => 'region', 'code' => 'US-CA', 'name' => 'California']], array_diff_key($this->data($california), ['id' => true]));

        $settings = $this->request('PUT', '/tax/settings', ['basis' => 'shipping_address', 'default_group_id' => $group], nonce: true);
        self::assertSame(200, $settings->get_status());
        self::assertSame(['basis' => 'shipping_address', 'default_group_id' => $group, 'bases' => ['shipping_address', 'billing_address', 'shop_address', 'eu_origin', 'eu_destination'], 'prices_include_tax' => false, 'reverse_charge' => false, 'rounds_each_line' => false, 'origin_regions' => [], 'provider' => null, 'when_provider_fails' => 'shop_rates', 'providers' => []], $this->data($settings));

        $calculator = self::kernel()->container()->get(TaxCalculator::class);
        self::assertInstanceOf(TaxCalculator::class, $calculator);
        $taxes = $calculator->taxes(new TaxQuery(shipping: new TaxAddress('US', 'CA')));
        self::assertSame('9.25', $taxes->total);
        self::assertSame(['California', 'Federal'], array_column($taxes->taxes, 'name'));

        /** @var list<array{name: string, rates: list<array{name: string}>}> $listed */
        $listed = $this->data($this->request('GET', '/tax/groups'));
        self::assertSame(['Standard'], array_column($listed, 'name'));
        self::assertSame(['California', 'Federal'], array_column($listed[0]['rates'], 'name'));

        self::assertSame(204, $this->request('DELETE', "/tax/groups/{$group}/rates/{$federal}", nonce: true)->get_status());
        // A new request, as the next page load would be: Doctrine keeps what it loaded for one request.
        $calculator = self::kernel()->container()->get(TaxCalculator::class);
        self::assertInstanceOf(TaxCalculator::class, $calculator);
        self::assertSame('7.25', $calculator->taxes(new TaxQuery(shipping: new TaxAddress('US', 'CA')))->total);
        self::assertSame([[$group], [$group], [$group]], $this->announced);
    }

    public function testARateForSomePostcodesOnTopOfTheOthers(): void
    {
        $this->actAs('administrator');
        update_option(OptionSettingsRepository::OPTION, ['currency' => 'USD', 'fewer_decimals' => null, 'weight_unit' => 'lb', 'dimension_unit' => 'in', 'shop_country' => 'US', 'shop_state' => 'NY']);
        $group = $this->id($this->request('POST', '/tax/groups', ['name' => 'Standard'], nonce: true));
        $this->request('POST', "/tax/groups/{$group}/rates", ['country' => 'US', 'state' => 'CA', 'percent' => '5', 'name' => 'State'], nonce: true);
        $local = $this->request('POST', "/tax/groups/{$group}/rates", ['country' => 'US', 'state' => 'CA', 'percent' => '10', 'name' => 'City', 'priority' => 1, 'postcodes' => ['900*'], 'compound' => true], nonce: true);
        self::assertSame(201, $local->get_status());
        self::assertSame(['900*'], $this->data($local)['postcodes'] ?? null);
        self::assertSame(422, $this->request('POST', "/tax/groups/{$group}/rates", ['country' => 'US', 'state' => 'CA', 'percent' => '1', 'postcodes' => ['9-1']], nonce: true)->get_status());
        $this->request('PUT', '/tax/settings', ['basis' => 'shipping_address', 'default_group_id' => $group, 'rounds_each_line' => true], nonce: true);

        $calculator = self::kernel()->container()->get(TaxCalculator::class);
        self::assertInstanceOf(TaxCalculator::class, $calculator);
        self::assertSame('15.5', $calculator->taxes(new TaxQuery(shipping: new TaxAddress('US', 'CA', '90012')))->total);
        self::assertSame('5', $calculator->taxes(new TaxQuery(shipping: new TaxAddress('US', 'CA', '94103')))->total);
        self::assertTrue($calculator->roundsEachLine());
    }

    public function testInvalidRatesAreRefused(): void
    {
        $this->actAs('administrator');
        $group = $this->id($this->request('POST', '/tax/groups', ['name' => 'Standard'], nonce: true));

        $invalid = $this->request('POST', "/tax/groups/{$group}/rates", ['country' => 'de', 'state' => null, 'percent' => '19,5', 'priority' => -1], nonce: true);
        self::assertSame(422, $invalid->get_status());
        self::assertSame(['country', 'percent', 'priority'], array_column($this->violations($invalid), 'field'));

        $foreignState = $this->request('POST', "/tax/groups/{$group}/rates", ['country' => 'DE', 'state' => 'CA', 'percent' => '19'], nonce: true);
        self::assertSame(422, $foreignState->get_status());
        self::assertSame('orillacart_unknown_state', $this->data($foreignState)['code']);

        self::assertSame(404, $this->request('PUT', "/tax/groups/{$group}/rates/999999", ['country' => 'DE', 'state' => null, 'percent' => '19'], nonce: true)->get_status());
        self::assertSame(404, $this->request('GET', '/tax/groups/999999')->get_status());
    }

    public function testTheDefaultGroupStaysUntilAnotherIsChosen(): void
    {
        $this->actAs('administrator');
        $group = $this->id($this->request('POST', '/tax/groups', ['name' => 'Standard'], nonce: true));
        $this->request('POST', "/tax/groups/{$group}/rates", ['country' => 'BG', 'state' => null, 'percent' => '20'], nonce: true);
        $this->request('PUT', '/tax/settings', ['basis' => 'shop_address', 'default_group_id' => $group], nonce: true);

        $refused = $this->request('DELETE', "/tax/groups/{$group}", nonce: true);
        self::assertSame(422, $refused->get_status());
        self::assertSame('orillacart_tax_group_in_use', $this->data($refused)['code']);

        $this->request('PUT', '/tax/settings', ['basis' => 'shop_address', 'default_group_id' => null], nonce: true);
        self::assertSame(204, $this->request('DELETE', "/tax/groups/{$group}", nonce: true)->get_status());
        self::assertSame([], $this->data($this->request('GET', '/tax/groups')));

        self::assertSame('0', $this->wpdb()->get_var("SELECT COUNT(*) FROM {$this->wpdb()->prefix}orillacart_tax_rates"), 'rates go with their group');
    }

    public function testTheTaxScreenIsInTheShopMenu(): void
    {
        global $submenu;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $this->actAs('administrator');

        do_action('admin_menu');

        self::assertIsArray($submenu);
        $items = $submenu['orillacart'] ?? [];
        self::assertIsArray($items);
        self::assertContains('Tax', array_column($items, 0));
    }

    private function id(\WP_REST_Response $response): int
    {
        $id = $this->data($response)['id'] ?? null;
        self::assertIsInt($id);

        return $id;
    }

    private function wpdb(): \wpdb
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);

        return $wpdb;
    }

    public function testTheShopSeesWhichStatesItsSalesCrossedTheThresholdsOf(): void
    {
        $this->actAs('administrator');
        update_option(OptionSettingsRepository::OPTION, ['currency' => 'USD', 'fewer_decimals' => null, 'weight_unit' => 'lb', 'dimension_unit' => 'in', 'shop_country' => 'US', 'shop_state' => 'NY']);
        self::assertSame(['everywhere' => ['sales' => '100000', 'orders' => null], 'states' => []], $this->data($this->request('GET', '/tax/nexus-thresholds')), 'the usual $100,000');

        $saved = $this->request('PUT', '/tax/nexus-thresholds', ['everywhere' => ['sales' => '100.00', 'orders' => null], 'states' => [['state' => 'TX', 'sales' => null, 'orders' => 2]]], nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
        self::assertSame(['everywhere' => ['sales' => '100', 'orders' => null], 'states' => [['state' => 'TX', 'sales' => null, 'orders' => 2]]], $this->data($saved));
        foreach ([['everywhere' => ['sales' => '0']], ['everywhere' => ['sales' => 'lots']], ['everywhere' => [], 'states' => [['state' => 'Texas', 'orders' => 1]]], ['everywhere' => [], 'states' => [['state' => 'TX'], ['state' => 'TX']]], ['everywhere' => [], 'states' => [['orders' => 1]]]] as $refused) {
            self::assertSame(422, $this->request('PUT', '/tax/nexus-thresholds', $refused, nonce: true)->get_status(), (string) wp_json_encode($refused));
        }
        $group = $this->id($this->request('POST', '/tax/groups', ['name' => 'Standard'], nonce: true));
        $this->request('POST', "/tax/groups/{$group}/rates", ['country' => 'US', 'state' => 'CA', 'percent' => '7.25', 'name' => 'California'], nonce: true);

        $tables = new ReportTables(new EntityManagerFactory(DatabaseConfig::fromWordPress(), new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))->create());
        $store = new DbalReportStore($tables);
        $today = new \DateTimeImmutable((string) wp_date('Y-m-d'));
        $sold = static fn(int $id, string $state, string $total): OrderFacts => new OrderFacts(new SoldOrder($id, $today, $today, true, 'processing', 'USD', "c{$id}", null, 'Ada Lovelace', 'US', 'Bank transfer', '', 1, $total, '0', '0', '0', $total, $state), [], [], [], []);
        $orders = [990101, 990102, 990103, 990104];
        $store->replace($orders, [$sold(990101, 'TX', '30'), $sold(990102, 'TX', '30'), $sold(990103, 'CA', '150'), $sold(990104, 'NV', '60')]);
        try {
            $report = $this->data($this->request('GET', '/reports/nexus'));
            self::assertSame('USD', $report['currency']);
            self::assertSame(
                [['TX', 'Texas', '60', 2, true, false], ['CA', 'California', '150', 1, true, true], ['NV', 'Nevada', '60', 1, false, false]],
                array_map(static function (array $state): array {
                    $year = $state['this_year'];
                    self::assertIsArray($year);

                    return [$state['state'], $state['name'], $year['sales'], $year['orders'], $state['crossed'], $state['collecting']];
                }, self::rows($report['states'])),
                'Texas counts orders and the shop collects nothing there: it comes first',
            );
        } finally {
            $store->replace($orders, []);
            delete_option(OptionNexusThresholdsRepository::OPTION);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsList($value);

        /** @var list<array<string, mixed>> */
        return $value;
    }

    private function clean(): void
    {
        $this->wpdb()->query("DELETE FROM {$this->wpdb()->prefix}orillacart_tax_groups");
        delete_option(OptionTaxSettingsRepository::OPTION);
        delete_option(OptionSettingsRepository::OPTION);
    }
}
