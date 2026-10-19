<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress;

use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\VariationPostType;

final readonly class WordPressProductContent implements ProductContent
{
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

    public function termIds(int $productId): array
    {
        return ['categories' => self::terms($productId, ProductCategoryTaxonomy::NAME), 'brands' => self::terms($productId, ProductBrandTaxonomy::NAME)];
    }

    public function productIdsInTerms(array $categoryIds, array $brandIds): array
    {
        $ids = [];
        foreach ([ProductCategoryTaxonomy::NAME => $categoryIds, ProductBrandTaxonomy::NAME => $brandIds] as $taxonomy => $termIds) {
            if ($termIds === []) {
                continue;
            }
            $below = [];
            foreach ($termIds as $termId) {
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
    private static function terms(int $productId, string $taxonomy): array
    {
        $terms = get_the_terms($productId, $taxonomy);
        $ids = [];
        foreach (\is_array($terms) ? $terms : [] as $term) {
            foreach ([$term->term_id, ...get_ancestors($term->term_id, $taxonomy, 'taxonomy')] as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
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

    public function deleteVariation(int $variationId): void
    {
        if (get_post_type($variationId) === VariationPostType::NAME) {
            wp_delete_post($variationId, true);
        }
    }
}
