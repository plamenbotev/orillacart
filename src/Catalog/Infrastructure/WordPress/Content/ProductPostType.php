<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Content;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\PostTypeDefinition;

/**
 * Product posts hold a product's name, description, images and permalink; the Catalog tables hold the rest.
 */
final readonly class ProductPostType implements PostTypeDefinition
{
    public const string NAME = 'orillacart_product';

    public function name(): string
    {
        return self::NAME;
    }

    public function arguments(): array
    {
        return [
            'labels' => [
                'name' => __('Products', 'orillacart'),
                'singular_name' => __('Product', 'orillacart'),
                'all_items' => __('All products', 'orillacart'),
                'add_new_item' => __('Add new product', 'orillacart'),
                'edit_item' => __('Edit product', 'orillacart'),
                'new_item' => __('New product', 'orillacart'),
                'view_item' => __('View product', 'orillacart'),
                'search_items' => __('Search products', 'orillacart'),
                'not_found' => __('No products found', 'orillacart'),
                'not_found_in_trash' => __('No products found in the trash', 'orillacart'),
            ],
            'public' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-products',
            'menu_position' => 57,
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions'],
            // The shop page lists products, with prices and choices; a theme's archive would not.
            'has_archive' => false,
            'rewrite' => ['slug' => 'product', 'with_front' => false],
            'map_meta_cap' => true,
            'capabilities' => self::capabilities(),
        ];
    }

    /**
     * Everything about products needs the shop capability.
     *
     * @return array<string, string>
     */
    private static function capabilities(): array
    {
        $primitive = ['edit_posts', 'edit_others_posts', 'edit_private_posts', 'edit_published_posts', 'publish_posts',
            'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_private_posts', 'delete_published_posts', 'create_posts'];

        return array_fill_keys($primitive, Capability::MANAGE_SHOP);
    }
}
