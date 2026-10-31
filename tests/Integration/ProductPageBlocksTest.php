<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSettingsRepository;

/**
 * The product page's own blocks, rendered on the server: the SKU and stock lines the script changes with the
 * chosen variation, the short description, the specifications, the share links and the delivery estimate.
 */
final class ProductPageBlocksTest extends RestTestCase
{
    private const array TABLES = ['shipping_rates', 'carriers', 'stock_levels', 'attributes', 'products'];

    private int $hoodie;
    private mixed $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->settings = get_option(OptionSettingsRepository::OPTION);
        update_option(OptionSettingsRepository::OPTION, ['currency' => 'EUR', 'fewer_decimals' => null, 'weight_unit' => 'kg', 'dimension_unit' => 'cm', 'shop_country' => 'BG', 'shop_state' => null]);
        $this->actAs('administrator');
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Hoodie', 'post_excerpt' => 'Warm & soft.', 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->hoodie = $id;
        self::assertSame(200, $this->request('PUT', "/products/{$id}", [
            'price' => '40',
            'sku' => 'HOOD-' . $id,
            'stock_mode' => 'always_available',
            'weight' => '0.5',
            'length' => '30',
            'width' => '20',
            'height' => '5',
            'attributes' => [
                ['name' => 'Material', 'options' => [['name' => 'Cotton'], ['name' => 'Wool']]],
                ['name' => 'Gift wrap', 'options' => [['name' => 'Yes', 'adjustment' => '3']], 'in_specifications' => false],
            ],
        ], nonce: true)->get_status());
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        wp_delete_post($this->hoodie, true);
        $this->clean();
        update_option(OptionSettingsRepository::OPTION, $this->settings);
        parent::tearDown();
    }

    public function testTheSkuStockAndShortDescriptionNameTheProductForTheScript(): void
    {
        $html = $this->blocks('product-sku', 'product-stock', 'product-excerpt', 'product-downloads');

        self::assertStringContainsString('<span data-orillacart-sku>HOOD-' . $this->hoodie . '</span>', $html);
        self::assertMatchesRegularExpression('#<p class="orillacart-stock" data-orillacart-stock>\s*In stock\s*</p>#', $html, 'stock not counted: in stock');
        self::assertStringContainsString('<p>Warm &amp; soft.</p>', $html);
        self::assertStringContainsString('data-orillacart-variation-description hidden', $html, 'where the chosen variation\'s text goes');
        self::assertSame(3, substr_count($html, 'data-orillacart-for="' . $this->hoodie . '"'), 'each part names the product');
        self::assertStringNotContainsString('Files you get', $html, 'no files, no list');
    }

    public function testTheSpecificationsListTheAttributesTheShopChoseWithTheMeasures(): void
    {
        $html = $this->blocks('product-specifications');

        self::assertStringContainsString('<caption class="orillacart-heading">Specifications</caption>', $html);
        self::assertStringContainsString('<th scope="row">Material</th><td>Cotton, Wool</td>', $html);
        self::assertStringNotContainsString('Gift wrap', $html, 'left out of the specifications');
        self::assertStringContainsString('<td>0.5 kg</td>', $html);
        self::assertStringContainsString('<td>30 × 20 × 5 cm</td>', $html);
        self::assertStringNotContainsString('Weight', do_blocks('<!-- wp:orillacart/product-specifications {"productId":' . $this->hoodie . ',"showWeight":false} /-->'));
    }

    public function testTheShareLinksAreTheOwnersChoiceAndLoadNothingOfTheNetworks(): void
    {
        $html = do_blocks('<!-- wp:orillacart/product-share {"productId":' . $this->hoodie . ',"services":["email","facebook","whatsapp"]} /-->');
        $url = rawurlencode((string) get_permalink($this->hoodie));

        self::assertStringContainsString('href="https://www.facebook.com/sharer/sharer.php?u=' . $url . '"', $html);
        self::assertStringContainsString('href="mailto:?subject=Hoodie&amp;body=' . $url . '"', $html);
        self::assertStringContainsString('https://wa.me/?text=Hoodie%20' . $url, $html);
        self::assertStringNotContainsString('pinterest', $html, 'not chosen');
        self::assertStringNotContainsString('data-orillacart-share-copy', $html, 'nor copying');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testTheDeliveryEstimateComesFromTheCarriersThatDeliverToTheShopper(): void
    {
        self::assertSame('', trim($this->blocks('delivery-estimate')), 'no carrier says when it delivers');

        $this->actAs('administrator');
        $post = $this->request('POST', '/carriers', ['name' => 'Post', 'rates' => [['name' => 'Bulgaria', 'price' => '4', 'regions' => ['BG']]], 'delivery_min_days' => 2, 'delivery_max_days' => 4], nonce: true);
        self::assertSame(201, $post->get_status());
        $id = $this->data($post)['id'];
        self::assertIsInt($id);
        $saved = $this->data($this->request('GET', "/carriers/{$id}"));
        self::assertSame([2, 4], [$saved['delivery_min_days'], $saved['delivery_max_days']], 'the API keeps the working days');
        self::assertSame(201, $this->request('POST', '/carriers', ['name' => 'Courier', 'rates' => [['name' => 'Germany', 'price' => '9', 'regions' => ['DE']]], 'delivery_min_days' => 0, 'delivery_max_days' => 0], nonce: true)->get_status());
        wp_set_current_user(0);

        $html = $this->blocks('delivery-estimate');
        self::assertMatchesRegularExpression('#<span data-orillacart-delivery-text>Get it between [^<]+ and [^<]+</span>#', $html, 'the post, which delivers to the shop\'s country: no cutoff to count down to');
        self::assertStringContainsString('data-orillacart-delivery="[{', $html, 'the days ahead, for the script');
    }

    public function testTheProductTemplateShowsTheShortDescriptionAndTheDeliveryEstimate(): void
    {
        $template = get_block_template('orillacart//single-' . ProductPostType::NAME);
        if (!$template instanceof \WP_Block_Template) {
            self::markTestSkipped('The test site\'s theme is not a block theme.');
        }

        self::assertStringContainsString('<!-- wp:orillacart/product-excerpt /-->', $template->content);
        self::assertStringContainsString('<!-- wp:orillacart/delivery-estimate /-->', $template->content);
    }

    private function blocks(string ...$names): string
    {
        return do_blocks(implode('', array_map(fn(string $name): string => '<!-- wp:orillacart/' . $name . ' {"productId":' . $this->hoodie . '} /-->', $names)));
    }

    private function clean(): void
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        foreach (self::TABLES as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
    }
}
