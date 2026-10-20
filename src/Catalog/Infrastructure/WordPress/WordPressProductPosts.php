<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Exchange\Application\Dto\ProductPost;
use OrillaCart\Exchange\Application\ProductPosts;

/**
 * Products' posts for the products file: a batch's posts in one query, their terms primed with them.
 */
final readonly class WordPressProductPosts implements ProductPosts
{
    public function of(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $posts = get_posts(['post_type' => ProductPostType::NAME, 'post_status' => 'any', 'include' => $ids, 'numberposts' => \count($ids), 'update_post_term_cache' => true]);
        $found = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $found[$post->ID] = new ProductPost(
                $post->post_status,
                $post->post_content,
                $post->post_excerpt,
                self::paths($post->ID, ProductCategoryTaxonomy::NAME),
                self::names($post->ID, ProductBrandTaxonomy::NAME),
                self::names($post->ID, ProductTagTaxonomy::NAME),
            );
        }

        return $found;
    }

    /** @return list<string> */
    private static function names(int $id, string $taxonomy): array
    {
        $terms = get_the_terms($id, $taxonomy);

        return \is_array($terms) ? array_values(array_map(static fn(\WP_Term $term): string => $term->name, $terms)) : [];
    }

    /** @return list<string> each category with its parents: "Lighting > Desk lamps" */
    private static function paths(int $id, string $taxonomy): array
    {
        $terms = get_the_terms($id, $taxonomy);
        if (!\is_array($terms)) {
            return [];
        }

        return array_values(array_map(static function (\WP_Term $term) use ($taxonomy): string {
            $names = [$term->name];
            foreach (get_ancestors($term->term_id, $taxonomy, 'taxonomy') as $ancestor) {
                $parent = get_term($ancestor, $taxonomy);
                if ($parent instanceof \WP_Term) {
                    array_unshift($names, $parent->name);
                }
            }

            return implode(' > ', $names);
        }, $terms));
    }
}
