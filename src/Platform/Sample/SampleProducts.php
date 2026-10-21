<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Sample;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Settings\Application\SampleData;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Persistence\LoadedEntities;

/**
 * Eight sample products in categories and brands: shirts and hoodies in sizes and colours (variations with
 * stock, one sold out), lamps (one on sale, one sold out), mugs with a colour to choose, and a gift card to
 * download, each with a picture of its colour where the server draws pictures (GD), and the Size and Colour
 * filters. Made through the shop's own API, so they are what the screens would make; each post, picture and
 * term carries META, and the option keeps the filters and stockroom it made, so removing takes only those.
 */
final readonly class SampleProducts implements SampleData
{
    public const string META = '_orillacart_sample';
    public const string OPTION = 'orillacart_sample_data';

    public function __construct(private LoadedEntities $entities) {}

    public function present(): bool
    {
        return get_option(self::OPTION) !== false;
    }

    public function add(): int
    {
        if ($this->present()) {
            return 0;
        }
        $made = ['filters' => [], 'stockroom' => null];
        $clothing = self::term('Clothing', ProductCategoryTaxonomy::NAME);
        $home = self::term('Home', ProductCategoryTaxonomy::NAME);
        $categories = [
            'T-shirts' => self::term('T-shirts', ProductCategoryTaxonomy::NAME, $clothing),
            'Hoodies' => self::term('Hoodies', ProductCategoryTaxonomy::NAME, $clothing),
            'Lamps' => self::term('Lamps', ProductCategoryTaxonomy::NAME, $home),
            'Mugs' => self::term('Mugs', ProductCategoryTaxonomy::NAME, $home),
            'Home' => $home,
        ];
        $brands = [];
        foreach (['Nordwind', 'Kraft & Co', 'Lumen'] as $brand) {
            $brands[$brand] = self::term($brand, ProductBrandTaxonomy::NAME);
        }
        $stockrooms = self::api('GET', '/stockrooms');
        $room = \is_array($stockrooms[0] ?? null) && is_numeric($stockrooms[0]['id'] ?? null) ? (int) $stockrooms[0]['id'] : null;
        if ($room === null) {
            $created = self::api('POST', '/stockrooms', ['name' => __('Warehouse', 'orillacart')]);
            $room = is_numeric($created['id'] ?? null) ? (int) $created['id'] : 0;
            $made['stockroom'] = $room;
        }
        // Saved first, so a failure halfway still lets remove() take what was made.
        update_option(self::OPTION, $made, false);

        $count = 0;
        foreach (self::products() as $position => [$title, $category, $brand, $colour, $excerpt, $details, $attributes, $variations]) {
            $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_status' => 'publish', 'post_title' => $title, 'post_excerpt' => $excerpt, 'post_content' => "<!-- wp:paragraph --><p>{$excerpt}</p><!-- /wp:paragraph -->", 'menu_order' => $position + 1], true);
            if ($id instanceof \WP_Error) {
                throw new \RuntimeException($id->get_error_message());
            }
            update_post_meta($id, self::META, 1);
            wp_set_object_terms($id, [$categories[$category]], ProductCategoryTaxonomy::NAME);
            if ($brand !== null) {
                wp_set_object_terms($id, [$brands[$brand]], ProductBrandTaxonomy::NAME);
            }
            if ($attributes !== []) {
                $details['attributes'] = array_map(static fn(string $name, array $options): array => ['name' => $name, 'options' => array_map(static fn(string $option): array => ['name' => $option], $options)], array_keys($attributes), $attributes);
            }
            self::api('PUT', "/products/{$id}", $details + ['weight' => '0.5']);
            if ($variations !== []) {
                $optionIds = [];
                $offered = self::api('GET', "/products/{$id}")['offered'] ?? [];
                foreach (\is_array($offered) ? $offered : [] as $attribute) {
                    $attributeName = \is_array($attribute) && \is_string($attribute['name'] ?? null) ? $attribute['name'] : null;
                    $options = \is_array($attribute) && \is_array($attribute['options'] ?? null) ? $attribute['options'] : [];
                    foreach ($options as $option) {
                        if ($attributeName !== null && \is_array($option) && \is_string($option['name'] ?? null) && is_numeric($option['id'] ?? null)) {
                            $optionIds[$attributeName][$option['name']] = (int) $option['id'];
                        }
                    }
                }
                foreach ($variations as [$options, $price, $stock]) {
                    $variation = self::api('POST', "/products/{$id}/variations", [
                        'option_ids' => array_values(array_map(static fn(string $name, string $option): int => $optionIds[$name][$option] ?? 0, array_keys($options), $options)),
                        'price' => $price,
                        'stock_mode' => 'tracked',
                    ]);
                    if (is_numeric($variation['id'] ?? null)) {
                        self::api('PUT', "/stock/variation/{$variation['id']}", ['levels' => [['stockroom_id' => $room, 'quantity' => $stock]]]);
                    }
                }
            }
            self::picture($id, $title, $colour);
            ++$count;
        }

        $filters = self::api('GET', '/search-filters');
        $keys = array_map(static fn(mixed $filter): string => \is_array($filter) && \is_string($filter['key'] ?? null) ? $filter['key'] : '', $filters);
        foreach ([['Size', 'size', ['Size'], 1], ['Colour', 'colour', ['Colour'], 2]] as [$label, $key, $names, $position]) {
            if (!\in_array($key, $keys, true)) {
                $filter = self::api('POST', '/search-filters', ['label' => $label, 'key' => $key, 'attributes' => $names, 'position' => $position]);
                if (is_numeric($filter['id'] ?? null)) {
                    $made['filters'][] = (int) $filter['id'];
                }
            }
        }
        update_option(self::OPTION, $made, false);
        // Each call saved what it changed; what they loaded is not the request's to keep.
        $this->entities->forget();

        return $count;
    }

    public function remove(): int
    {
        $made = get_option(self::OPTION);
        $count = 0;
        foreach (get_posts(['post_type' => [ProductPostType::NAME, 'attachment'], 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => self::META, 'fields' => 'ids']) as $post) {
            $count += get_post_type((int) $post) === ProductPostType::NAME ? 1 : 0;
            wp_delete_post((int) $post, true);
        }
        foreach ([ProductCategoryTaxonomy::NAME, ProductBrandTaxonomy::NAME] as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'meta_key' => self::META, 'fields' => 'ids']);
            // Children first, and only those no product of the shop's own is in.
            foreach (\is_array($terms) ? array_reverse($terms) : [] as $term) {
                $found = get_term((int) $term, $taxonomy);
                if ($found instanceof \WP_Term && $found->count === 0 && get_term_children((int) $term, $taxonomy) === []) {
                    wp_delete_term((int) $term, $taxonomy);
                }
            }
        }
        // Deleting the products removed their stock in bulk: the stock levels loaded before are stale now.
        $this->entities->forget();
        if (\is_array($made)) {
            foreach (\is_array($made['filters'] ?? null) ? $made['filters'] : [] as $filter) {
                if (is_numeric($filter)) {
                    self::api('DELETE', '/search-filters/' . (int) $filter, [], true);
                }
            }
            if (is_numeric($made['stockroom'] ?? null)) {
                self::api('DELETE', '/stockrooms/' . (int) $made['stockroom'], [], true);
            }
        }
        delete_option(self::OPTION);
        $this->entities->forget();

        return $count;
    }

    /**
     * Each product: title, category, brand, picture colour, words, details, attributes to choose, and variations as
     * [options, price, stock].
     *
     * @return list<array{string, string, ?string, string, string, array<string, mixed>, array<string, list<string>>, list<array{array<string, string>, string, int}>}>
     */
    private static function products(): array
    {
        return [
            ['Classic T-shirt', 'T-shirts', 'Nordwind', '#f2f2f2', 'Soft organic cotton, a relaxed fit.', ['price' => '19.90', 'featured' => true], ['Size' => ['S', 'M', 'L'], 'Colour' => ['Black', 'White']], [
                [['Size' => 'S', 'Colour' => 'Black'], '19.90', 12], [['Size' => 'M', 'Colour' => 'Black'], '19.90', 8], [['Size' => 'L', 'Colour' => 'Black'], '19.90', 0],
                [['Size' => 'S', 'Colour' => 'White'], '19.90', 5], [['Size' => 'M', 'Colour' => 'White'], '19.90', 6], [['Size' => 'L', 'Colour' => 'White'], '21.90', 3],
            ]],
            ['Zip Hoodie', 'Hoodies', 'Nordwind', '#2c3e50', 'Brushed fleece inside, a full zip.', ['price' => '49.00', 'featured' => true], ['Size' => ['M', 'L', 'XL'], 'Colour' => ['Black', 'Blue']], [
                [['Size' => 'M', 'Colour' => 'Black'], '49.00', 7], [['Size' => 'L', 'Colour' => 'Black'], '49.00', 3], [['Size' => 'XL', 'Colour' => 'Black'], '52.00', 1],
                [['Size' => 'L', 'Colour' => 'Blue'], '49.00', 4],
            ]],
            ['Desk Lamp', 'Lamps', 'Lumen', '#f1c40f', 'An adjustable arm and a warm LED.', ['price' => '59.00', 'stock_mode' => 'always_available'], [], []],
            ['Floor Lamp', 'Lamps', 'Lumen', '#7f8c8d', 'Tall and slim; sold out for now.', ['price' => '129.00', 'stock_mode' => 'unavailable'], [], []],
            ['Pendant Lamp', 'Lamps', 'Lumen', '#e67e22', 'Spun brass over the dining table.', ['price' => '89.00', 'stock_mode' => 'always_available', 'featured' => true, 'sale' => ['price' => '69.00']], [], []],
            ['Ceramic Mug', 'Mugs', 'Kraft & Co', '#2980b9', 'Glazed stoneware, 350 ml.', ['price' => '12.50', 'stock_mode' => 'always_available'], ['Colour' => ['White', 'Red', 'Blue']], []],
            ['Travel Mug', 'Mugs', 'Nordwind', '#16a085', 'Keeps coffee hot for six hours.', ['price' => '18.00', 'stock_mode' => 'always_available', 'sale' => ['price' => '14.40']], [], []],
            ['Gift Card', 'Home', null, '#8e44ad', 'Sent by email, for anything in the shop.', ['price' => '25.00', 'type' => 'virtual', 'stock_mode' => 'always_available'], [], []],
        ];
    }

    /**
     * Calls the shop's API as the shop manager.
     *
     * @param array<string, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    private static function api(string $method, string $route, array $body = [], bool $quiet = false): array
    {
        $request = new \WP_REST_Request($method, '/' . RouteCollection::REST_NAMESPACE . $route);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        if ($body !== []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }
        $response = rest_do_request($request);
        $data = $response->get_data();
        if ($response->get_status() >= 300 && !$quiet) {
            throw new \RuntimeException(\sprintf('%s %s answered %d: %s', $method, $route, $response->get_status(), (string) wp_json_encode($data)));
        }

        return \is_array($data) ? $data : [];
    }

    /** The term, made (and marked) when the shop has none of the name. */
    private static function term(string $name, string $taxonomy, int $parent = 0): int
    {
        $found = term_exists($name, $taxonomy, $parent);
        if (\is_array($found)) {
            return (int) $found['term_id'];
        }
        $made = wp_insert_term($name, $taxonomy, ['parent' => max(0, $parent)]);
        if ($made instanceof \WP_Error) {
            throw new \RuntimeException($made->get_error_message());
        }
        update_term_meta($made['term_id'], self::META, 1);

        return $made['term_id'];
    }

    /** A picture of the product's colour with its name, where the server can draw (GD). */
    private static function picture(int $product, string $name, string $hex): void
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagepng')) {
            return;
        }
        $size = 600;
        $image = imagecreatetruecolor($size, $size);
        $text = imagecreatetruecolor(9 * \strlen($name) + 8, 20);
        if ($image === false || $text === false) {
            return;
        }
        [$r, $g, $b] = array_map(static fn(mixed $part): int => max(0, min(255, is_numeric($part) ? (int) $part : 0)), sscanf($hex, '#%02x%02x%02x') ?? [0, 0, 0]);
        $background = (int) imagecolorallocate($image, $r, $g, $b);
        imagefill($image, 0, 0, $background);
        imagefill($text, 0, 0, (int) imagecolorallocate($text, $r, $g, $b));
        $light = ($r * 299 + $g * 587 + $b * 114) / 1000 > 140;
        imagestring($text, 5, 4, 2, $name, $light ? (int) imagecolorallocate($text, 30, 30, 30) : (int) imagecolorallocate($text, 250, 250, 250));
        $scale = min(4.0, ($size - 60) / imagesx($text));
        imagecopyresized($image, $text, (int) (($size - imagesx($text) * $scale) / 2), (int) (($size - 20 * $scale) / 2), 0, 0, (int) (imagesx($text) * $scale), (int) (20 * $scale), imagesx($text), 20);
        $file = wp_upload_dir()['path'] . '/orillacart-sample-' . $product . '.png';
        if (!imagepng($image, $file)) {
            return;
        }
        $attachment = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => $name, 'post_status' => 'inherit'], $file, $product);
        if (!\is_int($attachment) || $attachment === 0) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $file));
        update_post_meta($attachment, self::META, 1);
        set_post_thumbnail($product, $attachment);
    }
}
