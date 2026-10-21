<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\CategoryOrder;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\CategoryThumbnail;

/**
 * The product categories the storefront lists, in the shop's order: by their Order field, then by name.
 *
 * @phpstan-type Category array{id: int, name: string, slug: string, url: string, image_id: int, count: int, children: int}
 */
final readonly class ProductCategories
{
    /**
     * The top-level categories that have products, for the shop's links.
     *
     * @return list<array{id: int, name: string, slug: string, url: string, image: string}> image: the picture's HTML, or empty
     */
    public function ordered(): array
    {
        return array_map(static fn(array $category): array => [
            'id' => $category['id'],
            'name' => $category['name'],
            'slug' => $category['slug'],
            'url' => $category['url'],
            'image' => self::image($category['image_id'], 'thumbnail', 'orillacart-categories__image'),
        ], $this->childrenOf(0));
    }

    /**
     * The categories right below one (0: the top level), those without published products left out unless
     * $empty.
     *
     * @return list<Category>
     */
    public function childrenOf(int $parentId, bool $empty = false): array
    {
        $children = [];
        foreach ($this->all() as [$category, $parent]) {
            if ($parent === $parentId && ($empty || $category['count'] > 0)) {
                $children[] = $category;
            }
        }

        return $children;
    }

    /**
     * The categories chosen, in the order given; those gone, or without products unless $empty, left out.
     *
     * @param list<int> $ids
     *
     * @return list<Category>
     */
    public function chosen(array $ids, bool $empty = false): array
    {
        $byId = [];
        foreach ($this->all() as [$category]) {
            $byId[$category['id']] = $category;
        }
        $chosen = [];
        foreach ($ids as $id) {
            $category = $byId[$id] ?? null;
            if ($category !== null && ($empty || $category['count'] > 0)) {
                $chosen[] = $category;
            }
        }

        return $chosen;
    }

    /** The picture's HTML, or empty. */
    public static function image(int $attachmentId, string $size, string $class): string
    {
        return $attachmentId > 0 ? (string) wp_get_attachment_image($attachmentId, $size, false, ['class' => $class, 'loading' => 'lazy']) : '';
    }

    /**
     * Every category, in the shop's order, with its published products counted with those of the categories
     * below it, and how many categories are right below it; and the category above it (0 for none).
     *
     * @return list<array{Category, int}>
     */
    private function all(): array
    {
        $terms = get_terms(['taxonomy' => ProductCategoryTaxonomy::NAME, 'hide_empty' => false, 'pad_counts' => true]);
        if (!\is_array($terms)) {
            return [];
        }
        $terms = array_values(array_filter($terms, static fn(mixed $term): bool => $term instanceof \WP_Term));
        $children = [];
        foreach ($terms as $term) {
            $children[$term->parent] = ($children[$term->parent] ?? 0) + 1;
        }
        usort($terms, static fn(\WP_Term $a, \WP_Term $b): int => [CategoryOrder::position($a->term_id), $a->name] <=> [CategoryOrder::position($b->term_id), $b->name]);

        return array_map(static function (\WP_Term $term) use ($children): array {
            $url = get_term_link($term);

            return [[
                'id' => $term->term_id,
                'name' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'slug' => $term->slug,
                'url' => \is_string($url) ? $url : '',
                'image_id' => CategoryThumbnail::of($term->term_id),
                'count' => $term->count,
                'children' => $children[$term->term_id] ?? 0,
            ], $term->parent];
        }, $terms);
    }
}
