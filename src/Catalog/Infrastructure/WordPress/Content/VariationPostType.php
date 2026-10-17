<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Content;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\PostTypeDefinition;

/**
 * A variation's post, a child of its product's: its name, description (the excerpt), image and gallery. It has
 * no page of its own and is edited in the product's editor; the Catalog tables hold its price, SKU and stock.
 */
final readonly class VariationPostType implements PostTypeDefinition
{
    public const string NAME = 'orillacart_variation';

    public function name(): string
    {
        return self::NAME;
    }

    public function arguments(): array
    {
        return [
            'labels' => [
                'name' => __('Variations', 'orillacart'),
                'singular_name' => __('Variation', 'orillacart'),
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => true,
            'rest_base' => 'orillacart-variations',
            'supports' => ['title', 'excerpt', 'thumbnail', 'custom-fields'],
            'has_archive' => false,
            'rewrite' => false,
            'map_meta_cap' => true,
            'capabilities' => array_fill_keys(
                ['edit_posts', 'edit_others_posts', 'edit_private_posts', 'edit_published_posts', 'publish_posts', 'read_private_posts',
                    'delete_posts', 'delete_others_posts', 'delete_private_posts', 'delete_published_posts', 'create_posts'],
                Capability::MANAGE_SHOP,
            ),
        ];
    }
}
