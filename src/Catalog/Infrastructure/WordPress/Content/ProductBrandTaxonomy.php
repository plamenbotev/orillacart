<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Content;

final readonly class ProductBrandTaxonomy extends ProductTaxonomy
{
    public const string NAME = 'orillacart_brand';

    public function name(): string
    {
        return self::NAME;
    }

    public function arguments(): array
    {
        return self::common([
            'name' => __('Brands', 'orillacart'),
            'singular_name' => __('Brand', 'orillacart'),
            'all_items' => __('All brands', 'orillacart'),
            'edit_item' => __('Edit brand', 'orillacart'),
            'add_new_item' => __('Add new brand', 'orillacart'),
            'search_items' => __('Search brands', 'orillacart'),
            'not_found' => __('No brands found', 'orillacart'),
        ], 'brand', false);
    }
}
