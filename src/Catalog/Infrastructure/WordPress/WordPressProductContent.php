<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress;

use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\VariationPostType;
use OrillaCart\Shared\I18n\ContentLanguages;

final readonly class WordPressProductContent implements ProductContent
{
    public function __construct(private ContentLanguages $languages) {}

    public function exists(int $productId): bool
    {
        return get_post_type($productId) === ProductPostType::NAME;
    }

    public function isPublished(int $productId): bool
    {
        return $this->exists($productId) && get_post_status($productId) === 'publish';
    }

    public function name(int $productId): string
    {
        // Plain text: the title filters add HTML entities, and templates and emails escape names themselves.
        return $this->exists($productId) ? wp_strip_all_tags(html_entity_decode(get_the_title($productId), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    public function idsNamed(string $words, int $limit, bool $publishedOnly = false): array
    {
        $ids = get_posts([
            'post_type' => ProductPostType::NAME,
            'post_status' => $publishedOnly ? ['publish'] : ['publish', 'private', 'draft', 'pending', 'future'],
            's' => $words,
            'search_columns' => ['post_title'],
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
            // Every language: Polylang's posts of all of them, WPML's filters off.
            'lang' => '',
            'suppress_filters' => true,
        ]);

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    public function termIds(int $productId): array
    {
        return ['categories' => $this->terms($productId, ProductCategoryTaxonomy::NAME), 'brands' => $this->terms($productId, ProductBrandTaxonomy::NAME)];
    }

    public function productIdsInTerms(array $categoryIds, array $brandIds): array
    {
        $ids = [];
        foreach ([ProductCategoryTaxonomy::NAME => $categoryIds, ProductBrandTaxonomy::NAME => $brandIds] as $taxonomy => $termIds) {
            if ($termIds === []) {
                continue;
            }
            $below = [];
            // The terms in every language: a rule names them in the admin's (design 51).
            foreach ($this->withTranslations($termIds, $taxonomy) as $termId) {
                $children = get_term_children($termId, $taxonomy);
                $below[] = $termId;
                foreach (\is_array($children) ? $children : [] as $child) {
                    if (is_numeric($child)) {
                        $below[] = (int) $child;
                    }
                }
            }
            $objects = get_objects_in_term(array_values(array_unique($below)), $taxonomy);
            foreach (\is_array($objects) ? $objects : [] as $id) {
                if (is_numeric($id)) {
                    $ids[(int) $id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @return list<int>
     */
    private function terms(int $productId, string $taxonomy): array
    {
        $terms = get_the_terms($productId, $taxonomy);
        $ids = [];
        foreach (\is_array($terms) ? $terms : [] as $term) {
            foreach ([$term->term_id, ...get_ancestors($term->term_id, $taxonomy, 'taxonomy')] as $id) {
                $ids[$id] = true;
            }
        }

        // With their translations: a rule or coupon may name the category in another language (design 51).
        return $this->withTranslations(array_keys($ids), $taxonomy);
    }

    /**
     * @param list<int> $termIds
     *
     * @return list<int>
     */
    private function withTranslations(array $termIds, string $taxonomy): array
    {
        if (!$this->languages->multilingual()) {
            return $termIds;
        }
        $all = [];
        foreach ($termIds as $termId) {
            $all[$termId] = true;
            foreach ($this->languages->termTranslations($termId, $taxonomy) as $translation) {
                $all[$translation] = true;
            }
        }

        return array_keys($all);
    }

    public function createVariation(int $productId, string $name): int
    {
        $id = wp_insert_post([
            'post_type' => VariationPostType::NAME,
            'post_parent' => $productId,
            // Private: WordPress's REST API shows published posts of any type to anyone, which would show the
            // variations of draft products. The storefront reads them on the server.
            'post_status' => 'private',
            'post_title' => $name,
            'comment_status' => 'closed',
        ], true);
        if ($id instanceof \WP_Error) {
            throw new \RuntimeException('The variation\'s post could not be made: ' . $id->get_error_message());
        }

        return $id;
    }

    public function copy(int $productId): int
    {
        $post = get_post($productId);
        if (!$post instanceof \WP_Post || $post->post_type !== ProductPostType::NAME) {
            throw new \RuntimeException(\sprintf('There is no product post %d to copy.', $productId));
        }
        $id = wp_insert_post([
            'post_type' => ProductPostType::NAME,
            'post_status' => 'draft',
            /* translators: %s: the product's name */
            'post_title' => \sprintf(__('%s (copy)', 'orillacart'), $post->post_title),
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'menu_order' => $post->menu_order,
        ], true);
        if ($id instanceof \WP_Error) {
            throw new \RuntimeException('The copy\'s post could not be made: ' . $id->get_error_message());
        }
        foreach (get_object_taxonomies(ProductPostType::NAME) as $taxonomy) {
            $terms = wp_get_object_terms($productId, $taxonomy, ['fields' => 'ids']);
            if (\is_array($terms) && $terms !== []) {
                wp_set_object_terms($id, array_map(intval(...), $terms), $taxonomy);
            }
        }
        $this->copyPictures($productId, $id);

        return $id;
    }

    public function copyVariation(int $fromVariationId, int $toVariationId): void
    {
        $from = get_post($fromVariationId);
        if (!$from instanceof \WP_Post || $from->post_type !== VariationPostType::NAME || get_post_type($toVariationId) !== VariationPostType::NAME) {
            return;
        }
        if ($from->post_excerpt !== '') {
            wp_update_post(['ID' => $toVariationId, 'post_excerpt' => $from->post_excerpt]);
        }
        $this->copyPictures($fromVariationId, $toVariationId);
    }

    private function copyPictures(int $from, int $to): void
    {
        foreach (['_thumbnail_id', 'orillacart_gallery'] as $key) {
            $value = get_post_meta($from, $key, true);
            if ($value !== '' && $value !== false) {
                update_post_meta($to, $key, $value);
            }
        }
    }

    public function deleteVariation(int $variationId): void
    {
        if (get_post_type($variationId) === VariationPostType::NAME) {
            wp_delete_post($variationId, true);
        }
    }
}
