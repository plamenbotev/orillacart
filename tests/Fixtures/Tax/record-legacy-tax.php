<?php

declare(strict_types=1);

/*
 * Records what the old plugin's tax helper returns on fixed data, so the rebuilt tax rules can be checked
 * against it (legacy-tax.json next to this file). Not part of the test suite: it runs inside a WordPress
 * that has the old plugin (branch modernize-php8-categories) active, as an admin request:
 *
 *   php record-legacy-tax.php /var/www/html > legacy-tax.json
 *
 * The rates are saved the way the old admin screen saved them, so a rate for a whole country is stored
 * with an empty state, never NULL.
 */

define('WP_ADMIN', true); // the customer helper then leaves its address fields to this script
require rtrim($argv[1] ?? '/var/www/html', '/') . '/wp-load.php';

global $wpdb;
$shop = Factory::getComponent('shop');

$wpdb->query("DELETE FROM {$wpdb->prefix}shop_tax_rate");
$wpdb->query("DELETE FROM {$wpdb->prefix}shop_tax_group");

$groups = [];
foreach (['standard' => 'Standard', 'reduced' => 'Reduced', 'zero' => 'Zero'] as $key => $name) {
    $group = $shop->getTable('tax_group');
    $group->bind(['tax_group_name' => $name, 'published' => 1])->store();
    $groups[$key] = (int) $wpdb->get_var("SELECT tax_group_id FROM {$wpdb->prefix}shop_tax_group WHERE tax_group_name = '{$name}'");
}

// [group, country, state ('' = whole country), percent, name, priority]
$rates = [
    ['standard', 'BG', '', '20', 'VAT', 1],
    ['standard', 'BG', '', '1.5', 'Eco', 0],
    ['standard', 'DE', '', '19', 'MwSt', 1],
    ['standard', 'US', '', '2', 'Federal', 2],
    ['standard', 'US', 'CA', '7.25', 'California', 1],
    ['standard', 'US', 'NY', '4', 'New York', 1],
    ['standard', 'US', 'NY', '0.375', 'MCTD', 2],
    ['standard', 'CA', '', '5', 'GST', 1],
    ['standard', 'GB', '', '20', 'VAT', 1],
    ['standard', 'IT', '', '22', '', 1],
    ['reduced', 'BG', '', '9', 'VAT reduced', 1],
    ['reduced', 'DE', '', '7', 'MwSt reduced', 1],
    ['reduced', 'US', 'CA', '1', 'CA reduced', 1],
];
$rateIds = [];
foreach ($rates as $i => [$group, $country, $state, $percent, $name, $priority]) {
    // As the old save_rate task did: bind the posted form, then the state select's value ('' when none).
    $row = $shop->getTable('tax_rate')->bind([
        'tax_group_id' => $groups[$group], 'tax_country' => $country, 'tax_rate' => $percent,
        'tax_name' => $name, 'priority' => $priority,
    ]);
    $row->tax_state = $state;
    $row->store();
    $rateIds[(int) $wpdb->get_var("SELECT MAX(tax_rate_id) FROM {$wpdb->prefix}shop_tax_rate")] = $i;
}

$setParams = static function (array $values) use ($shop): void {
    $params = $shop->getParams();
    foreach ($values as $key => $value) {
        $params->set($key, $value);
    }
    wp_cache_set('params', $params, 'com_shop');
    foreach ($values as $key => $value) {
        if ($shop->getParams()->get($key) !== $value) {
            throw new RuntimeException("Could not set the {$key} setting");
        }
    }
};

// The customer helper reads addresses from its form fields; give it fixed ones.
$customer = $shop->getHelper('customer');
$setAddress = static function (?array $shipping, ?array $billing) use ($customer): void {
    $field = static fn(?string $value): object => new class ($value) {
        public function __construct(private ?string $value) {}
        public function get_value(): ?string
        {
            return $this->value;
        }
    };
    $fields = [];
    foreach (['shipping' => $shipping, 'billing' => $billing] as $type => $address) {
        $fields[$type] = (object) [
            $type . '_country' => $field($address[0] ?? null),
            $type . '_state' => $field($address[1] ?? null),
        ];
    }
    new ReflectionProperty($customer, 'fields')->setValue($customer, $fields);
};

$tax = $shop->getHelper('tax');
$record = static function () use ($tax, $rateIds, $groups): callable {
    return static function (?string $country, ?string $state, ?string $group) use ($tax, $rateIds, $groups): array {
        $gid = $group === null ? null : $groups[$group];
        $rate = $tax->get_tax_rate($country, $state, $gid);
        $matched = [];
        foreach ($tax->get_matched_rates($country, $state, $gid) as $id => $line) {
            $matched[] = $rateIds[$id];
        }

        return ['rate' => round((float) $rate, 8), 'lines' => $matched];
    };
};
$calculate = $record();

$modes = ['0' => 'shipping', '1' => 'shop', '2' => 'eu', '3' => 'billing'];
$shops = [[null, null], ['BG', null], ['US', 'NY'], ['DE', null]];
$addresses = [
    [null, null], ['BG', null], ['DE', null], ['GB', null], ['JP', null], ['CA', null], ['CA', 'ON'],
    ['US', null], ['US', 'CA'], ['US', 'NY'], ['US', 'TX'], ['US', 'ZZ'], ['BG', 'CA'],
];
$taxGroups = ['standard', 'reduced', 'zero', null];

$cases = [];
// Explicit address, as order totals and product prices asked: get_tax_rate($country, $state, $group).
foreach ($modes as $mode => $modeName) {
    foreach ($shops as $shopAddress) {
        foreach ([null, 'standard'] as $defaultGroup) {
            $setParams(['vatType' => (int) $mode, 'shop_country' => $shopAddress[0], 'shop_state' => $shopAddress[1],
                'shop_tax_group' => $defaultGroup === null ? null : $groups[$defaultGroup]]);
            $setAddress(null, null);
            foreach ($addresses as $address) {
                foreach ($taxGroups as $group) {
                    $cases[] = [
                        'mode' => $modeName, 'shop' => $shopAddress, 'default_group' => $defaultGroup,
                        'call' => 'explicit', 'address' => $address, 'group' => $group,
                    ] + $calculate($address[0], $address[1], $group);
                }
            }
        }
    }
}
// No address passed, as the cart asked: the helper reads the customer's shipping or billing address.
foreach ($modes as $mode => $modeName) {
    foreach ($shops as $shopAddress) {
        $setParams(['vatType' => (int) $mode, 'shop_country' => $shopAddress[0], 'shop_state' => $shopAddress[1],
            'shop_tax_group' => $groups['standard']]);
        foreach ([[['US', 'CA'], ['DE', null]], [['DE', null], ['US', 'NY']], [['JP', null], ['BG', null]], [null, null]] as [$shipping, $billing]) {
            $setAddress($shipping, $billing);
            foreach (['standard', null] as $group) {
                $cases[] = [
                    'mode' => $modeName, 'shop' => $shopAddress, 'default_group' => 'standard',
                    'call' => 'customer', 'shipping' => $shipping ?? [null, null], 'billing' => $billing ?? [null, null],
                    'group' => $group,
                ] + $calculate(null, null, $group);
            }
        }
    }
}

echo json_encode([
    'source' => 'modernize-php8-categories@' . (getenv('LEGACY_COMMIT') ?: 'unknown') . ', helpers/tax.php',
    'rates' => array_map(static fn(array $r): array => array_combine(['group', 'country', 'state', 'percent', 'name', 'priority'], $r), $rates),
    'eu' => array_map('strval', $wpdb->get_col("SELECT country_2_code FROM {$wpdb->prefix}shop_country WHERE in_eu = 'yes' ORDER BY country_2_code")),
    'cases' => $cases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
