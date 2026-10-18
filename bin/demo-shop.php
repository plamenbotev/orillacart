<?php

/*
 * Fills a development site with a small shop to try the storefront on: ten products in categories and brands,
 * with sizes and colours (some as variations, one under a Bulgarian attribute name), sales, stock that runs
 * out, pictures, the Size and Colour filters, and a shop page with the filters in a column beside the products.
 * Run again to start over: it removes what it made before. Development only; run inside wp-env:
 *
 *   npx wp-env run cli --env-cwd=wp-content/plugins/orillacart wp eval-file bin/demo-shop.php
 *
 * Then build the search index: wp orillacart index work
 */

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;

const DEMO_META = '_orillacart_demo';

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admins[0] ?? 1));

/**
 * Calls the plugin's REST API as the administrator, failing loudly.
 *
 * @param array<string, mixed> $body
 *
 * @return array<mixed>
 */
function demo_api(string $method, string $route, array $body = []): array
{
    $request = new WP_REST_Request($method, '/orillacart/v1' . $route);
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    if ($body !== []) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    $response = rest_do_request($request);
    $data = $response->get_data();
    if ($response->get_status() >= 300) {
        WP_CLI::error(sprintf('%s %s answered %d: %s', $method, $route, $response->get_status(), wp_json_encode($data)));
    }

    return is_array($data) ? $data : [];
}

/** A picture for the product: its colour, with its name. */
function demo_image(int $product, string $name, string $hex): void
{
    $width = 600;
    $image = imagecreatetruecolor($width, $width);
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    imagefill($image, 0, 0, imagecolorallocate($image, (int) $r, (int) $g, (int) $b));
    // GD's own font is small: draw the name small, then scale it up.
    $text = imagecreatetruecolor(9 * strlen($name) + 8, 20);
    imagefill($text, 0, 0, imagecolorallocate($text, (int) $r, (int) $g, (int) $b));
    $light = ($r * 299 + $g * 587 + $b * 114) / 1000 > 140;
    imagestring($text, 5, 4, 2, $name, $light ? imagecolorallocate($text, 30, 30, 30) : imagecolorallocate($text, 250, 250, 250));
    $scaled = min(4.0, ($width - 60) / imagesx($text));
    imagecopyresized($image, $text, (int) (($width - imagesx($text) * $scaled) / 2), (int) (($width - 20 * $scaled) / 2), 0, 0, (int) (imagesx($text) * $scaled), (int) (20 * $scaled), imagesx($text), 20);
    $file = wp_upload_dir()['path'] . '/orillacart-demo-' . $product . '.png';
    imagepng($image, $file);

    $attachment = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => $name, 'post_status' => 'inherit'], $file, $product);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $file));
    update_post_meta($attachment, DEMO_META, 1);
    set_post_thumbnail($product, $attachment);
}

/** @return int the term's id, made under the parent when missing */
function demo_term(string $name, string $taxonomy, int $parent = 0): int
{
    $found = term_exists($name, $taxonomy, $parent);
    if (is_array($found)) {
        return (int) $found['term_id'];
    }
    $made = wp_insert_term($name, $taxonomy, ['parent' => $parent]);
    if ($made instanceof WP_Error) {
        WP_CLI::error($made->get_error_message());
    }
    update_term_meta($made['term_id'], DEMO_META, 1);

    return $made['term_id'];
}

// Start over: what the demo made before goes, with its stock and search documents (the plugin's hooks).
foreach (get_posts(['post_type' => [ProductPostType::NAME, 'attachment'], 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => DEMO_META, 'fields' => 'ids']) as $old) {
    wp_delete_post((int) $old, true);
}
foreach (demo_api('GET', '/search-filters') as $filter) {
    if (is_array($filter) && in_array($filter['key'] ?? '', ['size', 'colour'], true)) {
        demo_api('DELETE', '/search-filters/' . $filter['id']);
    }
}

$clothing = demo_term('Clothing', ProductCategoryTaxonomy::NAME);
$home = demo_term('Home', ProductCategoryTaxonomy::NAME);
$categories = [
    'T-shirts' => demo_term('T-shirts', ProductCategoryTaxonomy::NAME, $clothing),
    'Hoodies' => demo_term('Hoodies', ProductCategoryTaxonomy::NAME, $clothing),
    'Lamps' => demo_term('Lamps', ProductCategoryTaxonomy::NAME, $home),
    'Mugs' => demo_term('Mugs', ProductCategoryTaxonomy::NAME, $home),
    'Home' => $home,
];
$brands = [];
foreach (['Nordwind', 'Kraft & Co', 'Lumen'] as $brand) {
    $brands[$brand] = demo_term($brand, ProductBrandTaxonomy::NAME);
}

$stockrooms = demo_api('GET', '/stockrooms');
$room = is_array($stockrooms[0] ?? null) ? (int) $stockrooms[0]['id'] : (int) demo_api('POST', '/stockrooms', ['name' => 'Sofia warehouse'])['id'];

/*
 * Each product: category, brand, colour of its picture, details, and its variations as
 * [[attribute => option, …], price, stock (null: always available)].
 */
$products = [
    ['Classic T-shirt', 'T-shirts', 'Nordwind', '#f2f2f2', ['price' => '19.90', 'featured' => true, 'excerpt' => 'Soft organic cotton, a relaxed fit.'], [
        'Size' => ['S', 'M', 'L'], 'Colour' => ['Black', 'White'],
    ], [
        [['Size' => 'S', 'Colour' => 'Black'], '19.90', 12], [['Size' => 'M', 'Colour' => 'Black'], '19.90', 8], [['Size' => 'L', 'Colour' => 'Black'], '19.90', 0],
        [['Size' => 'S', 'Colour' => 'White'], '19.90', 5], [['Size' => 'M', 'Colour' => 'White'], '19.90', 0], [['Size' => 'L', 'Colour' => 'White'], '21.90', 3],
    ]],
    ['Graphic T-shirt', 'T-shirts', 'Kraft & Co', '#c0392b', ['price' => '24.90', 'excerpt' => 'Screen-printed by hand, limited run.'], [
        'Size' => ['S', 'M', 'L'], 'Colour' => ['Red', 'Blue'],
    ], [
        [['Size' => 'S', 'Colour' => 'Red'], '24.90', 4, '19.90'], [['Size' => 'M', 'Colour' => 'Red'], '24.90', 6, '19.90'],
        [['Size' => 'M', 'Colour' => 'Blue'], '24.90', 2], [['Size' => 'L', 'Colour' => 'Blue'], '24.90', 0],
    ]],
    ['Zip Hoodie', 'Hoodies', 'Nordwind', '#2c3e50', ['price' => '49.00', 'featured' => true, 'excerpt' => 'Brushed fleece inside, a full zip.'], [
        'Size' => ['M', 'L', 'XL'], 'Colour' => ['Black', 'Blue'],
    ], [
        [['Size' => 'M', 'Colour' => 'Black'], '49.00', 7], [['Size' => 'L', 'Colour' => 'Black'], '49.00', 3], [['Size' => 'XL', 'Colour' => 'Black'], '52.00', 1],
        [['Size' => 'M', 'Colour' => 'Blue'], '49.00', 0], [['Size' => 'L', 'Colour' => 'Blue'], '49.00', 4],
    ]],
    // A product whose attribute has its Bulgarian name: the Size filter covers it too.
    ['Kids Hoodie', 'Hoodies', 'Kraft & Co', '#27ae60', ['price' => '34.00', 'excerpt' => 'Детски суитшърт, warm and easy to wash.'], [
        'Размер' => ['S', 'M'],
    ], [
        [['Размер' => 'S'], '34.00', 5], [['Размер' => 'M'], '34.00', 2],
    ]],
    ['Desk Lamp', 'Lamps', 'Lumen', '#f1c40f', ['price' => '59.00', 'stock_mode' => 'always_available', 'excerpt' => 'An adjustable arm and a warm LED.', 'attributes' => [
        ['name' => 'Colour', 'options' => [['name' => 'Black'], ['name' => 'White']]],
    ]], [], []],
    ['Floor Lamp', 'Lamps', 'Lumen', '#7f8c8d', ['price' => '129.00', 'stock_mode' => 'unavailable', 'excerpt' => 'Tall and slim, sold out for now.'], [], []],
    ['Pendant Lamp', 'Lamps', 'Lumen', '#e67e22', ['price' => '89.00', 'stock_mode' => 'always_available', 'featured' => true, 'sale' => ['price' => '69.00'], 'excerpt' => 'Spun brass over the dining table.'], [], []],
    ['Ceramic Mug', 'Mugs', 'Kraft & Co', '#2980b9', ['price' => '12.50', 'stock_mode' => 'always_available', 'excerpt' => 'Glazed stoneware, 350 ml.', 'attributes' => [
        ['name' => 'Colour', 'options' => [['name' => 'White'], ['name' => 'Red'], ['name' => 'Blue']]],
    ]], [], []],
    ['Travel Mug', 'Mugs', 'Nordwind', '#16a085', ['price' => '18.00', 'stock_mode' => 'always_available', 'sale' => ['price' => '14.40'], 'excerpt' => 'Keeps coffee hot for six hours.'], [], []],
    ['Gift Card', 'Home', null, '#8e44ad', ['price' => '25.00', 'type' => 'virtual', 'stock_mode' => 'always_available', 'excerpt' => 'Sent by email, for anything in the shop.'], [], []],
];

foreach ($products as $position => [$title, $category, $brand, $colour, $details, $attributes, $variations]) {
    $excerpt = $details['excerpt'];
    unset($details['excerpt']);
    $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_status' => 'publish', 'post_title' => $title, 'post_excerpt' => $excerpt, 'post_content' => "<!-- wp:paragraph --><p>{$excerpt}</p><!-- /wp:paragraph -->", 'menu_order' => $position + 1], true);
    if ($id instanceof WP_Error) {
        WP_CLI::error($id->get_error_message());
    }
    update_post_meta($id, DEMO_META, 1);
    wp_set_object_terms($id, [$categories[$category]], ProductCategoryTaxonomy::NAME);
    if ($brand !== null) {
        wp_set_object_terms($id, [$brands[$brand]], ProductBrandTaxonomy::NAME);
    }
    if ($attributes !== []) {
        $details['attributes'] = array_map(static fn(string $name, array $options): array => ['name' => $name, 'options' => array_map(static fn(string $option): array => ['name' => $option], $options)], array_keys($attributes), $attributes);
    }
    $details += ['weight' => '0.5'];
    demo_api('PUT', "/products/{$id}", $details);

    if ($variations !== []) {
        $optionIds = [];
        foreach (demo_api('GET', "/products/{$id}")['offered'] as $attribute) {
            foreach ($attribute['options'] as $option) {
                $optionIds[$attribute['name']][$option['name']] = $option['id'];
            }
        }
        foreach ($variations as $variation) {
            [$options, $price, $stock] = $variation;
            $body = ['option_ids' => array_values(array_map(static fn(string $name, string $option): int => $optionIds[$name][$option], array_keys($options), $options)), 'price' => $price, 'stock_mode' => 'tracked'];
            if (isset($variation[3])) {
                $body['sale'] = ['price' => $variation[3]];
            }
            $made = demo_api('POST', "/products/{$id}/variations", $body);
            demo_api('PUT', "/stock/variation/{$made['id']}", ['levels' => [['stockroom_id' => $room, 'quantity' => $stock]]]);
        }
    }
    demo_image($id, $title, $colour);
    WP_CLI::log("Made {$title}");
}

demo_api('POST', '/search-filters', ['label' => 'Size', 'key' => 'size', 'attributes' => ['Size', 'Размер'], 'position' => 1]);
demo_api('POST', '/search-filters', ['label' => 'Colour', 'key' => 'colour', 'attributes' => ['Colour'], 'position' => 2]);

// The shop page: the filters in a column beside the products.
$pages = get_option('orillacart_shop_pages');
$shop = is_array($pages) && is_int($pages['shop'] ?? null) ? $pages['shop'] : 0;
if ($shop > 0) {
    wp_update_post(['ID' => $shop, 'post_content' => <<<'BLOCKS'
        <!-- wp:columns {"align":"wide"} -->
        <div class="wp-block-columns alignwide"><!-- wp:column {"width":"26%"} -->
        <div class="wp-block-column" style="flex-basis:26%"><!-- wp:orillacart/product-filters /--></div>
        <!-- /wp:column -->

        <!-- wp:column {"width":"74%"} -->
        <div class="wp-block-column" style="flex-basis:74%"><!-- wp:orillacart/products {"filters":false,"columns":3,"perPage":12} /--></div>
        <!-- /wp:column --></div>
        <!-- /wp:columns -->
        BLOCKS]);
}

WP_CLI::success('The demo shop is ready: build its search index with `wp orillacart index work`, then open ' . ($shop > 0 ? get_permalink($shop) : 'the shop page') . '.');
