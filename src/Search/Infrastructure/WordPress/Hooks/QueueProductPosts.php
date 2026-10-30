<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Queues a product for the search index when its post changes: its words, status, order, categories, brands or tags;
 * or when it is deleted, so it leaves the index. A translation's queues its original while they are still linked:
 * the original's document has the translation's words. Last on the save (every change of status comes through
 * save_post too), after Polylang (at 21) and WPML have linked a new translation to its product; and again when
 * either links translations on their own screens.
 */
#[AsHook('save_post_' . ProductPostType::NAME, method: 'saved', priority: \PHP_INT_MAX)]
#[AsHook('before_delete_post', method: 'deleted', arguments: 2)]
#[AsHook('deleted_post', method: 'deleted', arguments: 2)]
#[AsHook('set_object_terms', method: 'filed', priority: \PHP_INT_MAX, arguments: 4)]
#[AsHook('pll_save_post_translations', method: 'linked')]
#[AsHook('wpml_pro_translation_completed', method: 'saved')]
#[AsHook('wpml_translation_update', method: 'wpmlChanged')]
final readonly class QueueProductPosts
{
    public function __construct(private SearchIndexing $indexing) {}

    public function saved(int $postId): void
    {
        if (get_post_type($postId) === ProductPostType::NAME) {
            $this->indexing->queue([$postId]);
        }
    }

    public function deleted(int $postId, \WP_Post $post): void
    {
        if ($post->post_type === ProductPostType::NAME) {
            $this->indexing->queue([$postId]);
        }
    }

    public function filed(int $objectId, mixed $terms, mixed $termTaxonomyIds, string $taxonomy): void
    {
        if (\in_array($taxonomy, [ProductCategoryTaxonomy::NAME, ProductBrandTaxonomy::NAME, ProductTagTaxonomy::NAME], true) && get_post_type($objectId) === ProductPostType::NAME) {
            $this->indexing->queue([$objectId]);
        }
    }

    /**
     * Polylang linked posts as translations of each other: the product among them gets the others' words.
     *
     * @param mixed $translations post id by language code
     */
    public function linked(mixed $translations): void
    {
        $products = [];
        foreach (\is_array($translations) ? $translations : [] as $id) {
            if (is_numeric($id) && get_post_type((int) $id) === ProductPostType::NAME) {
                $products[] = (int) $id;
            }
        }
        if ($products !== []) {
            $this->indexing->queue($products);
        }
    }

    /**
     * WPML saved, linked or removed a translation (wpml_translation_update, with the element's type and id).
     *
     * @param mixed $args array{type?: string, element_id?: int|string, element_type?: string}
     */
    public function wpmlChanged(mixed $args): void
    {
        $id = \is_array($args) && is_numeric($args['element_id'] ?? null) ? (int) $args['element_id'] : 0;
        if ($id > 0 && ($args['element_type'] ?? null) === 'post_' . ProductPostType::NAME) {
            $this->saved($id);
        }
    }
}
