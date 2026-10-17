<?php

declare(strict_types=1);

/*
 * Records what the old plugin answers about prices, options, stock and stock deduction on fixed data, so the
 * rebuilt Catalog and Inventory rules can be checked against it (legacy-catalog.json next to this file).
 * Not part of the test suite: it runs inside a WordPress that has the old plugin (branch
 * modernize-php8-categories) active:
 *
 *   php record-legacy-catalog.php /var/www/html > legacy-catalog.json
 *
 * Everything is named by a key ("mug", "clothing.size.m", "sofia"), never by a database id, and sale windows
 * are relative to the moment of recording ("active", "upcoming"), so the test can rebuild the same data.
 */

define('WP_ADMIN', true);
require rtrim($argv[1] ?? '/var/www/html', '/') . '/wp-load.php';
$legacy = WP_PLUGIN_DIR . '/orillacart/com_shop';
require_once $legacy . '/front/models/product.php';

global $wpdb;
$p = $wpdb->prefix;
$shop = Factory::getComponent('shop');

// ---------------------------------------------------------------------------------------------- the data

$stockrooms = [
    // key => [name, published, delivery unit, max delivery time]
    'sofia' => ['Sofia', 'yes', 'day', 2],
    'plovdiv' => ['Plovdiv', 'yes', 'day', 2],
    'berlin' => ['Berlin', 'yes', 'week', 1],
    'archive' => ['Archive', 'no', 'day', 1],
];

// Attribute sets: key => [published, attributes]; attribute: key => [name, required, ordering, options];
// option: key => [name, operator, price].
$sets = [
    'clothing' => ['yes', [
        'size' => ['Size', 'yes', 1, ['s' => ['S', '+', 0], 'm' => ['M', '+', 2], 'l' => ['L', '+', 5]]],
        'wrap' => ['Gift wrap', 'no', 2, ['yes' => ['Wrapped', '+', 3.5]]],
    ]],
    'hidden' => ['no', [
        'engraving' => ['Engraving', 'yes', 1, ['name' => ['Name', '+', 10]]],
    ]],
];

// Products: key => fields; sale: [price, window]; window: active | upcoming | ended | start-only | end-only.
$products = [
    'mug' => ['price' => '10', 'own' => [
        'colour' => ['Colour', 'no', 1, ['red' => ['Red', '+', 0], 'blue' => ['Blue', '-', 1.5], 'gold' => ['Gold', '+', 25], 'double' => ['Double', '*', 2]]],
    ]],
    'shirt' => ['price' => '20', 'sale' => ['15', 'active'], 'sets' => ['clothing']],
    'tee' => ['price' => '20', 'sale' => ['12', 'upcoming'], 'sets' => ['clothing']],
    'poster' => ['price' => '5', 'sale' => ['', 'active'], 'manage_stock' => 'no', 'own' => [
        'frame' => ['Frame', 'yes', 1, ['none' => ['No frame', '+', 0], 'wood' => ['Wood', '-', 8]]],
    ]],
    'jumper' => ['price' => '30', 'sale' => ['20', 'ended']],
    'cap' => ['price' => '8', 'sale' => ['6', 'start-only'], 'manage_stock' => 'yes', 'stock' => 'no'],
    'scarf' => ['price' => '12', 'sale' => ['9', 'end-only'], 'manage_stock' => 'yes', 'stock' => 'yes'],
    'plaque' => ['price' => '9', 'sets' => ['hidden']],
    'hoodie' => ['price' => '40', 'sets' => ['clothing'], 'variations' => [
        'hoodie-s' => ['price' => '38', 'options' => ['clothing.size.s']],
        'hoodie-m' => ['price' => '40', 'sale' => ['35', 'active'], 'options' => ['clothing.size.m']],
        'hoodie-l' => ['price' => '45', 'options' => ['clothing.size.l']],
    ]],
    'jacket' => ['price' => '60', 'sets' => ['clothing'], 'variations' => [
        'jacket-l' => ['price' => '65', 'options' => ['clothing.size.l']],
    ]],
];

// Stock levels: [item kind, item key, stockroom, quantity]; an item without rows has no stock levels at all.
$levels = [
    ['product', 'mug', 'sofia', 5], ['product', 'mug', 'plovdiv', 1], ['product', 'mug', 'berlin', 3], ['product', 'mug', 'archive', 10],
    ['product', 'tee', 'sofia', 0], ['product', 'tee', 'berlin', -2],
    ['product', 'jumper', 'archive', 4],
    ['product', 'hoodie-s', 'sofia', 2],
    ['product', 'hoodie-l', 'berlin', 0],
    ['option', 'clothing.size.m', 'sofia', 1],
    ['option', 'clothing.size.l', 'sofia', 0],
    ['option', 'clothing.wrap.yes', 'berlin', 6],
    ['option', 'mug.colour.blue', 'sofia', -1], ['option', 'mug.colour.blue', 'berlin', 4],
];

// What other shoppers hold in their carts: [item kind, item key, quantity].
$carts = [['product', 'mug', 2], ['option', 'clothing.size.m', 1], ['product', 'hoodie-s', 1]];

// ----------------------------------------------------------------------------------------- store it

foreach (['shop_variations', 'shop_property_stockroom_xref', 'shop_products_stockroom_xref', 'shop_product_attribsets',
    'shop_attribute_property', 'shop_attribute', 'shop_attribute_set', 'shop_stockroom', 'shop_cart'] as $table) {
    $wpdb->query("DELETE FROM {$p}{$table}");
}
foreach (get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1]) as $old) {
    wp_delete_post($old->ID, true);
}

$ids = ['stockroom' => [], 'set' => [], 'option' => [], 'product' => []];
foreach ($stockrooms as $key => [$name, $published, $unit, $max]) {
    $wpdb->insert("{$p}shop_stockroom", ['name' => $name, 'published' => $published, 'delivery_time' => $unit, 'min_del_time' => 0, 'max_del_time' => $max, 'desc' => '']);
    $ids['stockroom'][$key] = (int) $wpdb->insert_id;
}

$storeAttributes = static function (string $prefix, array $attributes, array $owner) use ($wpdb, $p, &$ids): void {
    foreach ($attributes as $key => [$name, $required, $ordering, $options]) {
        $wpdb->insert("{$p}shop_attribute", ['attribute_name' => $name, 'attribute_required' => $required, 'hide_attribute_price' => 'no', 'ordering' => $ordering] + $owner);
        $attributeId = (int) $wpdb->insert_id;
        $position = 0;
        foreach ($options as $optionKey => [$optionName, $operator, $price]) {
            $wpdb->insert("{$p}shop_attribute_property", ['attribute_id' => $attributeId, 'property_name' => $optionName, 'oprand' => $operator, 'property_price' => $price, 'ordering' => ++$position]);
            $ids['option']["{$prefix}.{$key}.{$optionKey}"] = (int) $wpdb->insert_id;
        }
    }
};
foreach ($sets as $key => [$published, $attributes]) {
    $wpdb->insert("{$p}shop_attribute_set", ['attribute_set_name' => ucfirst($key), 'published' => $published]);
    $ids['set'][$key] = (int) $wpdb->insert_id;
    $storeAttributes($key, $attributes, ['attribute_set_id' => $ids['set'][$key]]);
}

$windows = [
    'active' => ['-1 day', '+1 day'], 'upcoming' => ['+1 day', '+2 days'], 'ended' => ['-2 days', '-1 day'],
    'start-only' => ['-1 day', null], 'end-only' => [null, '+1 day'],
];
$saveProduct = static function (string $key, array $fields, int $parent = 0) use ($shop, $windows, &$ids): int {
    $id = wp_insert_post(['post_type' => 'product', 'post_title' => $key, 'post_status' => 'publish', 'post_parent' => $parent], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    // As the old product form saved it: bind the posted fields, store them as post meta.
    $row = $shop->getTable('product')->load($id);
    $row->price = $fields['price'];
    $row->manage_stock = $fields['manage_stock'] ?? 'global';
    $row->stock = $fields['stock'] ?? 'global';
    if (isset($fields['sale'])) {
        [$start, $end] = $windows[$fields['sale'][1]];
        $row->discount_price = $fields['sale'][0];
        $row->discount_start = $start === null ? '' : gmdate('Y-m-d H:i:s', strtotime($start));
        $row->discount_end = $end === null ? '' : gmdate('Y-m-d H:i:s', strtotime($end));
    }
    $row->store();
    wp_set_post_terms($id, 'regular', 'product_type');
    $ids['product'][$key] = $id;

    return $id;
};
foreach ($products as $key => $fields) {
    $id = $saveProduct($key, $fields);
    foreach ($fields['sets'] ?? [] as $set) {
        $wpdb->insert("{$p}shop_product_attribsets", ['pid' => $id, 'sid' => $ids['set'][$set]]);
    }
    $storeAttributes($key, $fields['own'] ?? [], ['product_id' => $id]);
    foreach ($fields['variations'] ?? [] as $variationKey => $variation) {
        $variationId = $saveProduct($variationKey, $variation, $id);
        foreach ($variation['options'] as $option) {
            $wpdb->insert("{$p}shop_variations", ['pid' => $variationId, 'prop' => $ids['option'][$option]]);
        }
    }
}

$itemId = static fn(string $kind, string $key): int => $ids[$kind === 'option' ? 'option' : 'product'][$key];
$resetLevels = static function () use ($wpdb, $p, $levels, $ids, $itemId): void {
    $wpdb->query("DELETE FROM {$p}shop_products_stockroom_xref");
    $wpdb->query("DELETE FROM {$p}shop_property_stockroom_xref");
    foreach ($levels as [$kind, $key, $stockroom, $quantity]) {
        $kind === 'product'
            ? $wpdb->insert("{$p}shop_products_stockroom_xref", ['product_id' => $itemId($kind, $key), 'stockroom_id' => $ids['stockroom'][$stockroom], 'stock' => $quantity])
            : $wpdb->insert("{$p}shop_property_stockroom_xref", ['property_id' => $itemId($kind, $key), 'stockroom_id' => $ids['stockroom'][$stockroom], 'stock' => $quantity]);
    }
};
$resetLevels();

$setParams = static function (array $values) use ($shop): void {
    $params = $shop->getParams();
    foreach ($values as $key => $value) {
        $params->set($key, $value);
    }
    wp_cache_set('params', $params, 'com_shop');
};

// --------------------------------------------------------------------------------------------- ask it

$optionKeys = array_flip($ids['option']);
$productKeys = array_flip($ids['product']);
$keysOf = static fn(array $optionIds): array => array_values(array_map(static fn($id): string => $optionKeys[(int) $id], $optionIds));

/** Every way to pick at most one option of each attribute the product has (own and assigned sets). */
$selections = static function (string $product) use ($products, $sets): array {
    $attributes = [];
    foreach ($products[$product]['own'] ?? [] as $key => $attribute) {
        $attributes[] = array_map(static fn(string $option): string => "{$product}.{$key}.{$option}", array_keys($attribute[3]));
    }
    foreach ($products[$product]['sets'] ?? [] as $set) {
        foreach ($sets[$set][1] as $key => $attribute) {
            $attributes[] = array_map(static fn(string $option): string => "{$set}.{$key}.{$option}", array_keys($attribute[3]));
        }
    }
    $all = [[]];
    foreach ($attributes as $options) {
        $next = [];
        foreach ($all as $selection) {
            $next[] = $selection;
            foreach ($options as $option) {
                $next[] = [...$selection, $option];
            }
        }
        $all = $next;
    }

    return $all;
};

$model = new productModel();
$helper = $shop->getHelper('product_helper');
$cart = $shop->getHelper('cart');
$cases = ['price' => [], 'offered' => [], 'required' => [], 'availability' => [], 'deduction' => []];

foreach ($products as $product => $fields) {
    $productSelections = $selections($product);
    // An option of another product, which the old price query ignored.
    $productSelections[] = ['mug.colour.gold'];
    if ($product === 'mug') {
        $productSelections[] = ['clothing.size.l'];
    }

    // Which options the product page offers.
    $offered = [];
    foreach ($model->getProductAttributes($ids['product'][$product])->atts as $attribute) {
        $offered[] = $keysOf(array_keys($attribute->properties));
    }
    $cases['offered'][] = ['product' => $product, 'options' => $offered];

    foreach ($productSelections as $selection) {
        $optionIds = array_map(static fn(string $key): int => $ids['option'][$key], $selection);
        $case = ['product' => $product, 'options' => $selection, 'price' => round((float) $helper->get_price($ids['product'][$product], $optionIds)->raw, 6)];
        if (isset($fields['variations'])) {
            $variation = $model->get_variation($ids['product'][$product], $optionIds);
            $case['variation'] = $variation ? $productKeys[$variation] : null;
        }
        $cases['price'][] = $case;

        // Adding to the cart checks the required attributes (and nothing about stock).
        $_SESSION['cart'] = [];
        $cases['required'][] = ['product' => $product, 'options' => $selection, 'accepted' => (bool) $cart->add_to_cart($ids['product'][$product], 1, $optionIds, [])];
        $cart->empty_cart();
    }

    foreach ($fields['variations'] ?? [] as $variationKey => $variation) {
        $cases['price'][] = ['product' => $variationKey, 'options' => [], 'price' => round((float) $helper->get_price($ids['product'][$variationKey], [])->raw, 6)];
    }
}

// Availability, with stock checks on and off, with and without other shoppers' carts.
$available = static fn($result): int|bool => is_bool($result) ? $result : (int) $result;
// The old code threw on some inputs (a query with an empty IN()); that is recorded as "error".
$attempt = static function (callable $ask): mixed {
    try {
        return $ask();
    } catch (Throwable) {
        return 'error';
    }
};
foreach ([true, false] as $checkStock) {
    $setParams(['checkStock' => $checkStock]);
    foreach ([false, true] as $withCarts) {
        $wpdb->query("DELETE FROM {$p}shop_cart");
        if ($withCarts) {
            foreach ($carts as $i => [$kind, $key, $quantity]) {
                $wpdb->insert("{$p}shop_cart", ['session_id' => "other-{$i}", 'product_id' => $itemId($kind, $key), 'section' => $kind === 'option' ? 'property' : 'product', 'qty' => $quantity, 'time' => time(), 'sess_group' => 0, 'last_access' => time()]);
            }
        }
        foreach ($products as $product => $fields) {
            $items = [$product => $selections($product)];
            foreach ($fields['variations'] ?? [] as $variationKey => $variation) {
                $items[$variationKey] = [[]];
            }
            foreach ($items as $item => $itemSelections) {
                foreach ($itemSelections as $selection) {
                    $optionIds = array_map(static fn(string $key): int => $ids['option'][$key], $selection);
                    $cases['availability'][] = [
                        'check_stock' => $checkStock, 'carts' => $withCarts, 'product' => $item, 'options' => $selection,
                        'available' => $attempt(static fn() => $available($model->is_variation_available($ids['product'][$item], $optionIds))),
                    ];
                }
            }
        }
    }
}
$wpdb->query("DELETE FROM {$p}shop_cart");

// Taking stock for a placed order: which stockrooms give how much.
$stockroomKeys = array_flip($ids['stockroom']);
$orderHelper = $shop->getHelper('order');
foreach ([['product', 'mug', [1, 2, 6, 9, 10, 25]], ['product', 'tee', [1]], ['product', 'jumper', [1]], ['product', 'shirt', [3]],
    ['option', 'mug.colour.blue', [3, 4, 5]], ['product', 'hoodie-s', [1, 3]]] as [$kind, $key, $quantities]) {
    foreach ($quantities as $quantity) {
        $resetLevels();
        $used = $orderHelper->manage_amounts($itemId($kind, $key), $quantity, $kind === 'option' ? 'property' : 'product');
        $taken = [];
        foreach ($used as $stockroom => $amount) {
            $taken[$stockroomKeys[$stockroom]] = (int) $amount;
        }
        $cases['deduction'][] = ['kind' => $kind, 'item' => $key, 'quantity' => $quantity, 'taken' => $taken];
    }
}
$resetLevels();

echo json_encode([
    'source' => 'modernize-php8-categories@' . (getenv('LEGACY_COMMIT') ?: 'unknown') . ', helpers/product_helper.php, front/models/product.php, helpers/cart.php, helpers/order.php',
    'stockrooms' => $stockrooms,
    'sets' => $sets,
    'products' => $products,
    'levels' => $levels,
    'carts' => $carts,
    'cases' => $cases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
