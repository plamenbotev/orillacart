<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress;

use OrillaCart\Catalog\Domain\ProductSearch;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;

final readonly class WordPressProductSearch implements ProductSearch
{
    private const array STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];

    public function find(?string $text, ?int $categoryId, array $alsoIds, int $offset, int $limit): array
    {
        $base = [
            'post_type' => ProductPostType::NAME,
            'post_status' => self::STATUSES,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
        ];
        if ($categoryId !== null) {
            $base['tax_query'] = [['taxonomy' => ProductCategoryTaxonomy::NAME, 'field' => 'term_id', 'terms' => $categoryId, 'include_children' => true]];
        }

        if ($text === null) {
            $query = new \WP_Query($base + ['posts_per_page' => $limit, 'offset' => $offset]);

            return [self::ids($query->posts), (int) $query->found_posts];
        }

        // By name, or by SKU (the ids the catalog found): two queries, one list in name order.
        $ids = self::ids(new \WP_Query($base + ['s' => $text, 'search_columns' => ['post_title'], 'posts_per_page' => -1, 'no_found_rows' => true])->posts);
        if ($alsoIds !== []) {
            $ids = [...$ids, ...self::ids(new \WP_Query($base + ['post__in' => $alsoIds, 'posts_per_page' => -1, 'no_found_rows' => true])->posts)];
        }
        $titles = [];
        foreach (array_unique($ids) as $id) {
            $titles[$id] = (string) get_post_field('post_title', $id, 'raw');
        }
        asort($titles, SORT_NATURAL | SORT_FLAG_CASE);

        return [\array_slice(array_keys($titles), $offset, $limit), \count($titles)];
    }

    /**
     * @param array<mixed>|null $posts
     *
     * @return list<int>
     */
    private static function ids(?array $posts): array
    {
        return array_values(array_filter($posts ?? [], \is_int(...)));
    }
}
