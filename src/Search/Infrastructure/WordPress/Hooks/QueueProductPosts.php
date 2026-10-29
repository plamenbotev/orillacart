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
 * the original's document has the translation's words.
 */
#[AsHook('save_post_' . ProductPostType::NAME, method: 'saved')]
#[AsHook('transition_post_status', method: 'transitioned', arguments: 3)]
#[AsHook('before_delete_post', method: 'deleted', arguments: 2)]
#[AsHook('deleted_post', method: 'deleted', arguments: 2)]
#[AsHook('set_object_terms', method: 'filed', arguments: 4)]
final readonly class QueueProductPosts
{
    public function __construct(private SearchIndexing $indexing) {}

    public function saved(int $postId): void
    {
        $this->indexing->queue([$postId]);
    }

    public function transitioned(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($post->post_type === ProductPostType::NAME && $newStatus !== $oldStatus) {
            $this->indexing->queue([$post->ID]);
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
}
