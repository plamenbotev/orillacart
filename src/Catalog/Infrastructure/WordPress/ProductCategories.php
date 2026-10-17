<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\CategoryOrder;

/**
 * The product categories the storefront lists, in the shop's order.
 */
final readonly class ProductCategories
{
    /**
     * The top-level categories that have products, by their Order field, then by name.
     *
     * @return list<array{id: int, name: string, slug: string}>
     */
    public function ordered(): array
    {
        $terms = get_terms(['taxonomy' => ProductCategoryTaxonomy::NAME, 'parent' => 0, 'hide_empty' => true]);
        if (!\is_array($terms)) {
            return [];
        }
        $categories = [];
        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $categories[] = ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'position' => CategoryOrder::position($term->term_id)];
            }
        }
        usort($categories, static fn(array $a, array $b): int => [$a['position'], $a['name']] <=> [$b['position'], $b['name']]);

        return array_map(static fn(array $category): array => ['id' => $category['id'], 'name' => html_entity_decode($category['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'slug' => $category['slug']], $categories);
    }
}
