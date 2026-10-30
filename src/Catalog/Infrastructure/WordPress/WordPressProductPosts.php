<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Exchange\Application\Dto\ProductPost;
use OrillaCart\Exchange\Application\Dto\ProductPostChange;
use OrillaCart\Exchange\Application\ProductPosts;

/**
 * Products' posts for the products file: a batch's posts in one query, their terms primed with them; and the
 * import's changes to them. WordPress takes the slashes off what it is given to save, so what goes to it is
 * slashed first: a backslash in a description stays.
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

    public function create(string $name, string $status): int
    {
        $id = wp_insert_post(wp_slash(['post_type' => ProductPostType::NAME, 'post_title' => $name, 'post_status' => $status]), true);
        if ($id instanceof \WP_Error) {
            throw new \RuntimeException($id->get_error_message());
        }

        return $id;
    }

    public function change(int $id, ProductPostChange $change): void
    {
        $post = array_filter([
            'post_title' => $change->name,
            'post_status' => $change->status,
            'post_content' => $change->description,
            'post_excerpt' => $change->shortDescription,
        ], static fn(?string $value): bool => $value !== null);
        if ($post !== []) {
            $updated = wp_update_post(wp_slash(['ID' => $id] + $post), true);
            if ($updated instanceof \WP_Error) {
                throw new \RuntimeException($updated->get_error_message());
            }
        }
        if ($change->categories !== null) {
            wp_set_object_terms($id, array_map(self::category(...), $change->categories), ProductCategoryTaxonomy::NAME);
        }
        if ($change->brands !== null) {
            wp_set_object_terms($id, array_map(self::term(...), $change->brands), ProductBrandTaxonomy::NAME);
        }
        if ($change->tags !== null) {
            wp_set_object_terms($id, array_map(self::term(...), $change->tags), ProductTagTaxonomy::NAME);
        }
    }

    public function delete(int $id): void
    {
        wp_delete_post($id, true);
    }

    public function asUser(?int $userId, \Closure $work): mixed
    {
        $was = get_current_user_id();
        wp_set_current_user($userId ?? 0);
        try {
            return $work();
        } finally {
            wp_set_current_user($was);
        }
    }

    /** A brand or tag by name, made where missing; its name slashed for WordPress, which unslashes it. */
    private static function term(string $name): string
    {
        return wp_slash($name);
    }

    /** The category a path names, made with its parents where missing. */
    private static function category(string $path): int
    {
        $parent = 0;
        foreach (array_filter(array_map(trim(...), explode('>', $path)), static fn(string $name): bool => $name !== '') as $name) {
            $found = term_exists(wp_slash($name), ProductCategoryTaxonomy::NAME, $parent);
            if (!\is_array($found)) {
                $found = wp_insert_term(wp_slash($name), ProductCategoryTaxonomy::NAME, ['parent' => max(0, $parent)]);
                if ($found instanceof \WP_Error) {
                    throw new \RuntimeException($found->get_error_message());
                }
            }
            $parent = (int) $found['term_id'];
        }

        return $parent;
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
