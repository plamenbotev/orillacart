<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Content;

final readonly class ProductCategoryTaxonomy extends ProductTaxonomy
{
    public const string NAME = 'orillacart_category';

    public function name(): string
    {
        return self::NAME;
    }

    public function arguments(): array
    {
        return self::common([
            'name' => __('Product categories', 'orillacart'),
            'singular_name' => __('Product category', 'orillacart'),
            'menu_name' => __('Categories', 'orillacart'),
            'all_items' => __('All categories', 'orillacart'),
            'edit_item' => __('Edit category', 'orillacart'),
            'add_new_item' => __('Add new category', 'orillacart'),
            'parent_item' => __('Parent category', 'orillacart'),
            'search_items' => __('Search categories', 'orillacart'),
            'not_found' => __('No categories found', 'orillacart'),
        ], 'product-category', true);
    }
}
