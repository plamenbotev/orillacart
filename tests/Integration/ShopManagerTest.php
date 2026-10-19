<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Platform\WordPress\Security\ShopCapabilities;

/**
 * A shop manager runs the shop and edits content like an editor, but cannot touch users, plugins or settings.
 */
final class ShopManagerTest extends RestTestCase
{
    public function testAShopManagerRunsTheShopButNotTheSite(): void
    {
        self::assertSame('Shop manager', wp_roles()->get_names()[ShopCapabilities::SHOP_MANAGER] ?? null);
        $manager = $this->actAs(ShopCapabilities::SHOP_MANAGER);

        foreach (['/orders', '/customers', '/settings', '/stock-sheet', '/attribute-sets'] as $route) {
            self::assertSame(200, $this->request('GET', $route)->get_status(), $route);
        }
        $product = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Lamp', 'post_status' => 'publish']);
        self::assertIsInt($product);
        self::assertTrue(current_user_can('edit_post', $product), 'edits products');
        wp_delete_post($product, true);

        foreach (['edit_others_posts', 'publish_pages', 'upload_files', 'moderate_comments'] as $capability) {
            self::assertTrue(user_can($manager, $capability), $capability);
        }
        foreach (['edit_users', 'list_users', 'promote_users', 'manage_options', 'activate_plugins', 'edit_theme_options', 'unfiltered_html'] as $capability) {
            self::assertFalse(user_can($manager, $capability), $capability);
        }
    }
}
