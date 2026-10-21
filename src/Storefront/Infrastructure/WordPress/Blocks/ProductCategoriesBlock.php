<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\ProductCategories;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockDefinition;

/**
 * The shop's categories as tiles, each its picture, name and, if asked, how many products it holds: the top
 * ones, those right below a category, or those chosen, in the shop's order (or the order chosen). On a
 * category's own page it shows those below that one, unless told not to; a category without any shows nothing.
 */
final readonly class ProductCategoriesBlock implements BlockDefinition
{
    public function __construct(
        private ProductCategories $categories,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/product-categories';
    }

    public function render(array $attributes): string
    {
        $empty = ($attributes['showEmpty'] ?? false) === true;
        $page = ($attributes['followPage'] ?? true) !== false && is_tax(ProductCategoryTaxonomy::NAME) ? get_queried_object() : null;
        $source = \is_string($attributes['source'] ?? null) ? $attributes['source'] : 'top';
        $chosen = array_values(array_filter(\is_array($attributes['include'] ?? null) ? $attributes['include'] : [], \is_int(...)));
        $categories = match (true) {
            $page instanceof \WP_Term => $this->categories->childrenOf($page->term_id, $empty),
            $source === 'chosen' => $this->categories->chosen($chosen, $empty),
            $source === 'below' && \is_int($attributes['parent'] ?? null) => $this->categories->childrenOf(max(0, $attributes['parent']), $empty),
            default => $this->categories->childrenOf(0, $empty),
        };
        if ($categories === []) {
            return '';
        }
        // Without a picture among them, the tiles are their names alone rather than empty frames.
        $showImage = ($attributes['showImage'] ?? true) !== false
            && array_filter($categories, static fn(array $category): bool => $category['image_id'] > 0) !== [];

        return $this->templates->render('storefront/product-categories.html.twig', [
            'categories' => array_map(static fn(array $category): array => [
                'name' => $category['name'],
                'url' => $category['url'],
                'count' => $category['count'],
                'image' => $showImage ? ProductCategories::image($category['image_id'], 'medium', 'orillacart-category-tile__image') : '',
            ], $categories),
            'columns' => max(1, min(6, \is_int($attributes['columns'] ?? null) ? $attributes['columns'] : 4)),
            'show_image' => $showImage,
            'show_count' => ($attributes['showCount'] ?? false) === true,
            // On a category's page, the tiles lead further into it.
            'label' => $page instanceof \WP_Term
                /* translators: %s: category name */
                ? \sprintf(__('Categories in %s', 'orillacart'), $page->name)
                : __('Product categories', 'orillacart'),
        ]);
    }
}
