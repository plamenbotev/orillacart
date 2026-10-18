<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Queues the products of a category or brand that was renamed, moved or deleted: the index keeps their names,
 * and a product is found under every category above its own. Before a category is deleted, its subcategories
 * still hang below it, so their products are queued too.
 */
#[AsHook('edited_term', method: 'edited', arguments: 3)]
#[AsHook('pre_delete_term', method: 'deleting', arguments: 2)]
final readonly class QueueTermProducts
{
    public function __construct(private SearchIndexing $indexing) {}

    public function edited(int $termId, int $termTaxonomyId, string $taxonomy): void
    {
        $this->queue($termId, $taxonomy);
    }

    public function deleting(int $termId, string $taxonomy): void
    {
        $this->queue($termId, $taxonomy);
    }

    private function queue(int $termId, string $taxonomy): void
    {
        if (!\in_array($taxonomy, [ProductCategoryTaxonomy::NAME, ProductBrandTaxonomy::NAME], true)) {
            return;
        }
        $children = get_term_children($termId, $taxonomy);
        $terms = [$termId, ...(\is_array($children) ? self::ids($children) : [])];
        $products = get_objects_in_term($terms, $taxonomy);
        if (\is_array($products)) {
            $this->indexing->queue(self::ids($products));
        }
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, array_filter($ids, is_numeric(...))));
    }
}
