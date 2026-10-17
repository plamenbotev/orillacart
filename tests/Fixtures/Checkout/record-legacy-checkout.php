<?php

declare(strict_types=1);

/*
 * Records what the old plugin answers at checkout on fixed data: cart totals and taxes, the shipping rates offered
 * with their tax, and the payment methods offered, so the rebuilt checkout can be checked against it
 * (legacy-checkout.json next to this file). Not part of the test suite: it runs inside a WordPress that has the old
 * plugin (branch modernize-php8-categories) active:
 *
 *   php record-legacy-checkout.php /var/www/html > legacy-checkout.json
 *
 * The old cart, shipping and payment helpers keep their first answer in static variables for the rest of the
 * request, so every case runs in a PHP process of its own (this script, called with "case" and the case as JSON).
 */

define('WP_ADMIN', true); // the customer helper then leaves its address fields to this script
require rtrim($argv[1] ?? '/var/www/html', '/') . '/wp-load.php';

global $wpdb;
$p = $wpdb->prefix;
$shop = Factory::getComponent('shop');

// ---------------------------------------------------------------------------------------------- the data

// [group, country, state ('' = whole country), percent, name]; only rates the old and new rules agree on.
$taxRates = [
    ['standard', 'BG', '', '20', 'VAT'],
    ['standard', 'DE', '', '19', 'MwSt'],
    ['standard', 'IT', '', '22', 'IVA'],
    ['standard', 'US', 'CA', '7.25', 'California'],
    ['reduced', 'BG', '', '9', 'VAT reduced'],
    ['reduced', 'DE', '', '7', 'MwSt reduced'],
];

// Products: key => [type, price, vat (global | yes | no), tax group (null = the shop's), weight, length, width, height].
// The old volume field is typed in by hand; here it is length × width × height, as the new model computes it.
$products = [
    'book' => ['regular', '10', 'global', 'reduced', 0.5, 20, 15, 3],
    'lamp' => ['regular', '33.33', 'global', null, 2.5, 30, 30, 50],
    'ebook' => ['virtual', '7.99', 'global', null, 0, 0, 0, 0],
    'freebie' => ['virtual', '0', 'global', null, 0, 0, 0, 0],
    'service' => ['virtual', '50', 'no', null, 0, 0, 0, 0],
    'mug' => ['regular', '12.5', 'yes', null, 0.4, 10, 8, 8],
];
// The mug's only option: [name, operator, price].
$mugOptions = ['gold' => ['Gold', '+', 2.5]];

// Carriers in their order, each with rates: key => fields. Ranges are [start, end], 0 for no end.
$carriers = [
    'courier' => ['Courier', [
        'bg-light' => ['name' => 'BG light', 'countries' => ['BG'], 'weight' => [0, 2], 'value' => 5, 'priority' => 1],
        'bg-heavy' => ['name' => 'BG heavy', 'countries' => ['BG'], 'weight' => [2, 0], 'value' => 9, 'priority' => 1],
        'eu' => ['name' => 'EU', 'countries' => ['DE', 'IT'], 'total' => [0, 99.99], 'value' => 15, 'tax_group' => 'reduced'],
        'eu-free' => ['name' => 'EU free', 'countries' => ['DE', 'IT'], 'total' => [100, 0], 'value' => 0],
        'california' => ['name' => 'California', 'countries' => ['US'], 'states' => ['CA'], 'value' => 2, 'per_item' => 'yes'],
        'express' => ['name' => 'Express', 'value' => 25, 'priority' => 5, 'vat' => 'yes', 'tax_group' => 'standard'],
    ]],
    'post' => ['Post', [
        'small' => ['name' => 'Small parcel', 'length' => [0, 50], 'value' => 3.5, 'vat' => 'no'],
        'bulky' => ['name' => 'Bulky', 'volume' => [50000, 0], 'value' => 30],
        'flat' => ['name' => 'Flat', 'height' => [0, 5], 'value' => 2],
    ]],
];

// Payment methods: class => [label, order, countries ([] = all)]. A method without a row is not offered.
$payments = [
    'payondelivery' => ['Cash on delivery', 1, ['BG']],
    'banktransfer' => ['Bank transfer', 2, []],
    'cheque' => ['Cheque', 3, ['US', 'CA']],
    'paypal' => ['PayPal', 4, []],
];

// Carts: key => lines [product, quantity, options].
$carts = [
    'one-book' => [['book', 1, []]],
    'books-and-lamp' => [['book', 3, []], ['lamp', 1, []]],
    'lamps-and-ebook' => [['lamp', 2, []], ['ebook', 1, []]],
    'ebooks' => [['ebook', 2, []]],
    'freebie' => [['freebie', 1, []]],
    'service-and-mugs' => [['service', 1, []], ['mug', 2, ['gold']]],
    'many-books' => [['book', 12, []]],
];

// Customers: key => [shipping country, shipping state, billing country, billing state].
$customers = [
    'nobody' => [null, null, null, null],
    'bg' => ['BG', null, 'BG', null],
    'de' => ['DE', null, 'DE', null],
    'it' => ['IT', null, 'IT', null],
    'us-ca' => ['US', 'CA', 'US', 'CA'],
    'us-ny' => ['US', 'NY', 'US', 'NY'],
    'jp' => ['JP', null, 'JP', null],
    'de-billing-bg' => ['DE', null, 'BG', null],
];

// Shop settings: key => params. vatType 0 taxes by the shipping address, 1 by the shop's, 3 by the billing address.
$settings = [
    'shipping-address' => ['vat' => true, 'vatType' => 0],
    'no-vat' => ['vat' => false, 'vatType' => 0],
    'shop-address' => ['vat' => true, 'vatType' => 1],
    'billing-address' => ['vat' => true, 'vatType' => 3],
];
$baseParams = ['shipping' => true, 'currency' => 'EUR', 'price_decimal' => 2, 'shop_country' => 'BG', 'shop_state' => null];

// ---------------------------------------------------------------------------------------- one case

$mode = $argv[2] ?? 'all';

if ($mode === 'case') {
    $case = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
    $ids = get_option('orillacart_recorder_ids');

    $params = $shop->getParams();
    foreach ($baseParams + $settings[$case['settings']] + ['shop_tax_group' => $ids['group']['standard']] as $key => $value) {
        $params->set($key, $value);
    }
    wp_cache_set('params', $params, 'com_shop');

    // The customer helper reads addresses from its form fields; give it fixed ones.
    [$shippingCountry, $shippingState, $billingCountry, $billingState] = $customers[$case['customer']];
    $field = static fn(?string $value): object => new class ($value) {
        public function __construct(private ?string $value) {}

        public function get_value(): ?string
        {
            return $this->value;
        }
    };
    $customer = $shop->getHelper('customer');
    new ReflectionProperty($customer, 'fields')->setValue($customer, [
        'shipping' => (object) ['shipping_country' => $field($shippingCountry), 'shipping_state' => $field($shippingState)],
        'billing' => (object) ['billing_country' => $field($billingCountry), 'billing_state' => $field($billingState)],
    ]);

    $cart = $shop->getHelper('cart');
    $_SESSION['cart'] = [];
    foreach ($carts[$case['cart']] as $k => [$product, $quantity, $options]) {
        $_SESSION['cart'][$k] = (object) [
            'id' => $ids['product'][$product], 'qty' => $quantity, 'files' => [],
            'props' => array_map(static fn(string $option): int => $ids['option'][$option], $options),
        ];
    }

    $round = static fn($value): float => round((float) $value, 6);
    $lines = [];
    while ($item = $cart->get_item()) {
        $lines[] = ['unit' => $round($item->raw_price), 'unit_tax' => $round($item->vat), 'quantity' => (int) $item->qty];
    }
    $taxes = [];
    foreach ($cart->get_cart_taxes() as $tax) {
        $taxes[] = ['name' => $tax->get('name', 'Tax'), 'amount' => $round($tax->get('value', 0))];
    }

    $rateKeys = array_flip($ids['rate']);
    $rates = [];
    $orderHelper = $shop->getHelper('order');
    foreach ($cart->get_shipping_rates() as $rate) {
        $id = json_decode($orderHelper->decryptShipping($rate->id));
        $rates[] = ['rate' => $rateKeys[(int) $id->rate_id], 'gross' => $round($rate->rate), 'tax' => $round($rate->rate - $rate->raw_rate)];
    }

    $result = [
        'lines' => $lines,
        'subtotal' => $round($cart->get_total_price()),
        'tax' => $round($cart->get_order_vat()),
        'taxes' => $taxes,
        'needs_shipping' => (bool) $cart->need_shipping(),
        'rates' => $rates,
    ];

    // The totals the old order kept, with the first rate offered chosen.
    $chosen = $cart->get_shipping_rates()[0] ?? null;
    if ($chosen !== null && $result['needs_shipping']) {
        $cart->set_shipping($chosen->id);
    }
    $result['order'] = [
        'subtotal' => round($cart->get_total_price(), 2),
        'tax' => round($cart->get_order_vat(), 2),
        'shipping' => round($cart->selected_shipping_rate() - $cart->selected_shipping_vat(), 2),
        'shipping_tax' => round($cart->selected_shipping_vat(), 2),
        'total' => round($cart->get_grand_total_price(), 2),
        'needs_payment' => $cart->need_payment(),
    ];

    $methodKeys = [];
    foreach ($cart->get_payment_methods() as $method) {
        $methodKeys[] = $method->get_class_name();
    }
    $result['payment_methods'] = $methodKeys;

    echo json_encode($result, JSON_PRESERVE_ZERO_FRACTION) . "\n";
    exit(0);
}

// ----------------------------------------------------------------------------------------- store it

foreach (['shop_shipping_rate', 'shop_methods', 'shop_tax_rate', 'shop_tax_group', 'shop_attribute_property', 'shop_attribute', 'shop_cart'] as $table) {
    $wpdb->query("DELETE FROM {$p}{$table}");
}
foreach (get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1]) as $old) {
    wp_delete_post($old->ID, true);
}

$ids = ['group' => [], 'product' => [], 'option' => [], 'rate' => []];
foreach (['standard' => 'Standard', 'reduced' => 'Reduced'] as $key => $name) {
    $wpdb->insert("{$p}shop_tax_group", ['tax_group_name' => $name, 'published' => 1]);
    $ids['group'][$key] = (int) $wpdb->insert_id;
}
foreach ($taxRates as $priority => [$group, $country, $state, $percent, $name]) {
    $wpdb->insert("{$p}shop_tax_rate", ['tax_group_id' => $ids['group'][$group], 'tax_country' => $country, 'tax_state' => $state, 'tax_rate' => $percent, 'tax_name' => $name, 'priority' => $priority]);
}

foreach ($products as $key => [$type, $price, $vat, $group, $weight, $length, $width, $height]) {
    $id = wp_insert_post(['post_type' => 'product', 'post_title' => $key, 'post_status' => 'publish'], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    // As the old product form saved it: bind the posted fields, store them as post meta.
    $row = $shop->getTable('product')->load($id);
    $row->type = $type;
    $row->price = $price;
    $row->vat = $vat;
    $row->tax_group_id = $group === null ? null : $ids['group'][$group];
    $row->product_weight = $weight;
    $row->product_length = $length;
    $row->product_width = $width;
    $row->product_height = $height;
    $row->product_volume = $length * $width * $height;
    $row->manage_stock = 'no';
    $row->store();
    wp_set_post_terms($id, $type, 'product_type');
    $ids['product'][$key] = $id;
}
$wpdb->insert("{$p}shop_attribute", ['attribute_name' => 'Finish', 'attribute_required' => 'no', 'hide_attribute_price' => 'no', 'ordering' => 1, 'product_id' => $ids['product']['mug']]);
$attributeId = (int) $wpdb->insert_id;
foreach ($mugOptions as $key => [$name, $operator, $price]) {
    $wpdb->insert("{$p}shop_attribute_property", ['attribute_id' => $attributeId, 'property_name' => $name, 'oprand' => $operator, 'property_price' => $price, 'ordering' => 1]);
    $ids['option'][$key] = (int) $wpdb->insert_id;
}

$order = 0;
foreach ($carriers as [$name, $rates]) {
    // As the old carrier form saved it: every condition "and".
    $wpdb->insert("{$p}shop_methods", ['name' => $name, 'type' => 'shipping', 'class' => null, 'method_order' => ++$order,
        'params' => json_encode(['weight' => 0, 'volume' => 0, 'length' => 0, 'width' => 0, 'height' => 0, 'total' => 0, 'zip' => 0, 'country' => 0, 'state' => 0])]);
    $carrierId = (int) $wpdb->insert_id;
    foreach ($rates as $key => $rate) {
        $range = static fn(string $measure): array => $rate[$measure] ?? [0, 0];
        $wpdb->insert("{$p}shop_shipping_rate", [
            'shipping_rate_name' => $rate['name'], 'carrier' => $carrierId,
            'shipping_rate_country' => isset($rate['countries']) ? implode(',', $rate['countries']) : null,
            'shipping_rate_state' => isset($rate['states']) ? implode(',', $rate['states']) : null,
            'shipping_rate_zip_start' => '', 'shipping_rate_zip_end' => '',
            'shipping_rate_weight_start' => $range('weight')[0], 'shipping_rate_weight_end' => $range('weight')[1],
            'shipping_rate_volume_start' => $range('volume')[0], 'shipping_rate_volume_end' => $range('volume')[1],
            'shipping_rate_ordertotal_start' => $range('total')[0], 'shipping_rate_ordertotal_end' => $range('total')[1],
            'shipping_rate_length_start' => $range('length')[0], 'shipping_rate_length_end' => $range('length')[1],
            'shipping_rate_width_start' => $range('width')[0], 'shipping_rate_width_end' => $range('width')[1],
            'shipping_rate_height_start' => $range('height')[0], 'shipping_rate_height_end' => $range('height')[1],
            'shipping_rate_priority' => $rate['priority'] ?? 0, 'shipping_rate_value' => $rate['value'],
            'apply_vat' => $rate['vat'] ?? 'global', 'qty_multiply' => $rate['per_item'] ?? 'no',
            'shipping_tax_group_id' => isset($rate['tax_group']) ? $ids['group'][$rate['tax_group']] : null,
            'shipping_location_info' => '',
        ]);
        $ids['rate'][$key] = (int) $wpdb->insert_id;
    }
}

foreach ($payments as $class => [$label, $position, $countries]) {
    $wpdb->insert("{$p}shop_methods", ['name' => $label, 'type' => 'payment', 'class' => $class, 'method_order' => $position, 'countries' => implode(',', $countries), 'params' => '{}']);
}
update_option('orillacart_recorder_ids', $ids, false);

// --------------------------------------------------------------------------------------------- ask it

$cases = [];
foreach (array_keys($settings) as $setting) {
    foreach (array_keys($carts) as $cart) {
        foreach (array_keys($customers) as $customer) {
            $case = ['settings' => $setting, 'cart' => $cart, 'customer' => $customer];
            $command = [PHP_BINARY, __FILE__, $argv[1] ?? '/var/www/html', 'case', json_encode($case)];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            if (proc_close($process) !== 0) {
                fwrite(STDERR, json_encode($case) . "\n" . $err . $out);
                exit(1);
            }
            $cases[] = $case + json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        }
    }
}
delete_option('orillacart_recorder_ids');

echo json_encode([
    'source' => 'modernize-php8-categories@' . (getenv('LEGACY_COMMIT') ?: 'unknown') . ', helpers/cart.php, methods/standart_shipping.php, front/models/order.php',
    'tax_rates' => $taxRates,
    'products' => $products,
    'mug_options' => $mugOptions,
    'carriers' => $carriers,
    'payments' => $payments,
    'carts' => $carts,
    'customers' => $customers,
    'settings' => $settings,
    'cases' => $cases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
