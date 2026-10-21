<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\ProductIdentifiers;
use OrillaCart\Reviews\Infrastructure\WordPress\CommentReviewStore;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionStorefrontSettingsRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\Sitemaps\ImageSitemapRenderer;

/**
 * What a product's page tells search engines and social sites, and what the sitemap says of products.
 */
final class ProductHeadTest extends RestTestCase
{
    private int $lamp;
    private int $image;
    private int $brand;
    private mixed $stored;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stored = get_option(OptionStorefrontSettingsRepository::OPTION);
        delete_option(OptionStorefrontSettingsRepository::OPTION);
        $this->actAs('administrator');
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Brass lamp', 'post_excerpt' => 'Warm <b>light</b> for reading.', 'post_status' => 'publish', 'comment_status' => 'open']);
        self::assertIsInt($id);
        $this->lamp = $id;
        self::assertSame(200, $this->request('PUT', "/products/{$id}", ['price' => '40', 'sku' => 'LAMP-' . $id, 'stock_mode' => 'always_available'], nonce: true)->get_status());
        update_post_meta($id, ProductIdentifiers::GTIN, '4006381333931');
        $term = wp_insert_term('Acme ' . uniqid(), ProductBrandTaxonomy::NAME);
        self::assertIsArray($term);
        $this->brand = $term['term_id'];
        wp_set_object_terms($id, [$this->brand], ProductBrandTaxonomy::NAME);
        $image = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'Lamp', 'post_status' => 'inherit'], 'lamp.jpg', $id);
        self::assertIsInt($image);
        $this->image = $image;
        set_post_thumbnail($id, $image);
        $review = wp_insert_comment(['comment_post_ID' => $id, 'comment_type' => CommentReviewStore::TYPE, 'comment_author' => 'Bob', 'comment_content' => 'Lovely.', 'comment_approved' => 1]);
        self::assertIsInt($review);
        add_comment_meta($review, CommentReviewStore::RATING, 4);
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        wp_delete_post($this->lamp, true);
        wp_delete_attachment($this->image, true);
        wp_delete_term($this->brand, ProductBrandTaxonomy::NAME);
        $this->stored === false ? delete_option(OptionStorefrontSettingsRepository::OPTION) : update_option(OptionStorefrontSettingsRepository::OPTION, $this->stored);
        remove_all_filters('orillacart_seo_plugin_active');
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_products");
        parent::tearDown();
    }

    public function testTheProductsPageDescribesItToSearchEnginesAndSocialSites(): void
    {
        $head = $this->head();
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $head, $match));
        $data = json_decode($match[1], true);
        self::assertIsArray($data);
        self::assertSame(['Product', 'Brass lamp', 'Warm light for reading.', 'LAMP-' . $this->lamp, '4006381333931'], [$data['@type'], $data['name'], $data['description'], $data['sku'], $data['gtin']]);
        self::assertIsArray($data['brand']);
        self::assertIsString($data['brand']['name']);
        self::assertStringStartsWith('Acme', $data['brand']['name']);
        $offers = $data['offers'];
        self::assertIsArray($offers);
        self::assertSame(['@type' => 'Offer', 'price' => '40', 'availability' => 'https://schema.org/InStock'], array_intersect_key($offers, array_flip(['@type', 'price', 'availability'])));
        self::assertIsString($offers['priceCurrency']);
        self::assertMatchesRegularExpression('/^[A-Z]{3}$/', $offers['priceCurrency'], 'the shop\'s currency');
        $rating = $data['aggregateRating'];
        self::assertIsArray($rating);
        self::assertSame(['4.0', 1], [$rating['ratingValue'], $rating['reviewCount']]);
        self::assertIsArray($data['image']);
        self::assertIsString($data['image'][0]);
        self::assertStringEndsWith('lamp.jpg', $data['image'][0]);

        self::assertStringContainsString('<meta property="og:type" content="product">', $head);
        self::assertStringContainsString('<meta property="product:price:amount" content="40">', $head);

        add_filter('orillacart_seo_plugin_active', '__return_true');
        $withSeoPlugin = $this->head();
        self::assertStringNotContainsString('og:type', $withSeoPlugin, 'an SEO plugin writes the tags');
        self::assertStringContainsString('application/ld+json', $withSeoPlugin, 'they do not describe products');

        update_option(OptionStorefrontSettingsRepository::OPTION, ['structured_data' => false, 'sharing_tags' => 'always']);
        $chosen = $this->head();
        self::assertStringNotContainsString('application/ld+json', $chosen);
        self::assertStringContainsString('og:type', $chosen, 'always, SEO plugin or not');
    }

    public function testTheSitemapListsTheProductsImagesAndWhenItChanged(): void
    {
        $provider = new \WP_Sitemaps_Posts();
        $entries = array_values(array_filter($provider->get_url_list(1, ProductPostType::NAME), fn(array $entry): bool => $entry['loc'] === get_permalink($this->lamp)));
        self::assertCount(1, $entries);
        self::assertArrayHasKey('lastmod', $entries[0]);
        self::assertIsArray($entries[0]['images']);

        $xml = new ImageSitemapRenderer()->get_sitemap_xml($entries);
        self::assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $xml);
        self::assertMatchesRegularExpression('#<image:image><image:loc>[^<]*lamp\.jpg</image:loc></image:image>#', $xml);
        self::assertStringNotContainsString('image', new ImageSitemapRenderer()->get_sitemap_xml([['loc' => 'https://example.com/']]), 'other sitemaps as WordPress writes them');
    }

    private function head(): string
    {
        global $wp_query, $wp_the_query, $post;
        // WordPress's own head tags read the host, which a command-line run does not have.
        $_SERVER['SERVER_NAME'] ??= 'localhost';
        $wp_query = new \WP_Query(['p' => $this->lamp, 'post_type' => ProductPostType::NAME]);
        $wp_the_query = $wp_query;
        $wp_query->the_post();
        ob_start();
        try {
            do_action('wp_head');
        } finally {
            $head = (string) ob_get_clean();
            wp_reset_postdata();
            $wp_query = $wp_the_query = new \WP_Query();
            $post = null;
        }

        return $head;
    }
}
