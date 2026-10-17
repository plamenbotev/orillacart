<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Content;

final readonly class ProductTagTaxonomy extends ProductTaxonomy
{
    public const string NAME = 'orillacart_tag';

    public function name(): string
    {
        return self::NAME;
    }

    public function arguments(): array
    {
        return self::common([
            'name' => __('Product tags', 'orillacart'),
            'singular_name' => __('Product tag', 'orillacart'),
            'menu_name' => __('Tags', 'orillacart'),
            'all_items' => __('All tags', 'orillacart'),
            'edit_item' => __('Edit tag', 'orillacart'),
            'add_new_item' => __('Add new tag', 'orillacart'),
            'search_items' => __('Search tags', 'orillacart'),
            'not_found' => __('No tags found', 'orillacart'),
        ], 'product-tag', false);
    }
}
