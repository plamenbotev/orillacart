<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Extension\Catalog\Categories;
use OrillaCart\Extension\Catalog\CategorySummary;

/** The shop's product categories as the extension API gives them to add-ons (design 69). */
final readonly class AddOnCategories implements Categories
{
    public function all(): array
    {
        $terms = get_terms(['taxonomy' => ProductCategoryTaxonomy::NAME, 'hide_empty' => false, 'lang' => '']);
        if (!\is_array($terms)) {
            return [];
        }
        $byId = [];
        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $byId[$term->term_id] = $term;
            }
        }
        $path = static function (\WP_Term $term) use ($byId): string {
            $names = [$term->name];
            $seen = [$term->term_id => true];
            for ($parent = $term->parent; $parent > 0 && isset($byId[$parent]) && !isset($seen[$parent]); $parent = $byId[$parent]->parent) {
                $seen[$parent] = true;
                array_unshift($names, $byId[$parent]->name);
            }

            return implode(' > ', $names);
        };
        $categories = array_map(static fn(\WP_Term $term): CategorySummary => new CategorySummary($term->term_id, $term->name, $term->parent, $path($term), $term->count), array_values($byId));
        usort($categories, static fn(CategorySummary $a, CategorySummary $b): int => strcasecmp($a->path, $b->path));

        return $categories;
    }

    public function ensure(string $path): int
    {
        if (trim(str_replace('>', '', $path)) === '') {
            throw new \InvalidArgumentException('Name the category: "Lighting > Desk lamps".');
        }
        try {
            return WordPressProductPosts::category($path);
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }
}
