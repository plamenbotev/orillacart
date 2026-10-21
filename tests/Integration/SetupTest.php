<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Platform\Sample\SampleProducts;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSetupState;

/**
 * The setup: the sample products, added and taken away with only what they brought, and whether the admin
 * still offers the setup.
 */
final class SetupTest extends RestTestCase
{
    private mixed $setup;
    private int $own = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setup = get_option(OptionSetupState::OPTION);
        delete_option(OptionSetupState::OPTION);
        $this->actAs('administrator');
        $this->request('DELETE', '/sample-data', nonce: true);
    }

    protected function tearDown(): void
    {
        $this->actAs('administrator');
        $this->request('DELETE', '/sample-data', nonce: true);
        if ($this->own > 0) {
            wp_delete_post($this->own, true);
        }
        $this->setup === false ? delete_option(OptionSetupState::OPTION) : update_option(OptionSetupState::OPTION, $this->setup);
        parent::tearDown();
    }

    public function testTheSampleProductsComeAndGoWithOnlyWhatTheyBrought(): void
    {
        self::assertFalse($this->data($this->request('GET', '/sample-data'))['present']);
        $added = $this->data($this->request('POST', '/sample-data', nonce: true));
        self::assertSame(8, $added['added']);
        self::assertSame(0, $this->data($this->request('POST', '/sample-data', nonce: true))['added'], 'once');
        self::assertCount(8, get_posts(['post_type' => ProductPostType::NAME, 'meta_key' => SampleProducts::META, 'numberposts' => -1, 'fields' => 'ids']));
        $lamps = get_term_by('name', 'Lamps', ProductCategoryTaxonomy::NAME);
        self::assertInstanceOf(\WP_Term::class, $lamps);

        // A product of the shop's own in a category the samples made keeps it.
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'My lamp', 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->own = $id;
        wp_set_object_terms($id, [$lamps->term_id], ProductCategoryTaxonomy::NAME);

        self::assertSame(8, $this->data($this->request('DELETE', '/sample-data', nonce: true))['removed']);
        self::assertSame([], get_posts(['post_type' => [ProductPostType::NAME, 'attachment'], 'post_status' => 'any', 'meta_key' => SampleProducts::META, 'numberposts' => -1, 'fields' => 'ids']));
        self::assertInstanceOf(\WP_Term::class, get_term_by('name', 'Lamps', ProductCategoryTaxonomy::NAME), 'the shop\'s own product is in it');
        self::assertFalse(get_term_by('name', 'Mugs', ProductCategoryTaxonomy::NAME));
        self::assertSame('publish', get_post_status($id));
    }

    public function testTheAdminOffersTheSetupUntilItIsFinishedOrPutOff(): void
    {
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        set_current_screen('dashboard');
        ob_start();
        do_action('admin_notices');
        self::assertStringContainsString('Set up the shop', (string) ob_get_clean());

        self::assertTrue($this->data($this->request('PUT', '/setup', nonce: true))['finished']);
        ob_start();
        do_action('admin_notices');
        self::assertStringNotContainsString('Set up the shop', (string) ob_get_clean());
    }
}
