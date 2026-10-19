<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Queues the products of a category or brand that was renamed, moved or deleted: the index keeps their names,
 * and a product is found under every category above its own. A change to anything else, such as the
 * description, queues nothing. A deleted category's products, its subcategories' too, are found before it goes,
 * while they still hang below it, and queued once the row is gone.
 */
#[AsHook('edit_terms', method: 'editing', arguments: 2)]
#[AsHook('edited_term', method: 'edited', arguments: 3)]
#[AsHook('pre_delete_term', method: 'deleting', arguments: 2)]
#[AsHook('delete_term', method: 'deleted', arguments: 3)]
final class QueueTermProducts
{
    /** @var array<int, array{string, int}> name and parent of the terms being edited, by term id */
    private array $before = [];

    /** @var array<int, list<int>> the products of the terms being deleted, by term id */
    private array $deleting = [];

    public function __construct(private readonly SearchIndexing $indexing) {}

    public function editing(int $termId, string $taxonomy): void
    {
        $term = self::shopTerm($termId, $taxonomy);
        if ($term !== null) {
            $this->before[$termId] = [$term->name, $term->parent];
        }
    }

    public function edited(int $termId, int|string $termTaxonomyId, string $taxonomy): void
    {
        $before = $this->before[$termId] ?? null;
        unset($this->before[$termId]);
        $term = self::shopTerm($termId, $taxonomy);
        if ($term !== null && $before !== [$term->name, $term->parent]) {
            $this->indexing->queue(self::products($termId, $taxonomy));
        }
    }

    public function deleting(int $termId, string $taxonomy): void
    {
        if (self::shopTerm($termId, $taxonomy) !== null) {
            $this->deleting[$termId] = self::products($termId, $taxonomy);
        }
    }

    public function deleted(int $termId, int|string $termTaxonomyId, string $taxonomy): void
    {
        $products = $this->deleting[$termId] ?? [];
        unset($this->deleting[$termId]);
        if ($products !== []) {
            $this->indexing->queue($products);
        }
    }

    private static function shopTerm(int $termId, string $taxonomy): ?\WP_Term
    {
        if (!\in_array($taxonomy, [ProductCategoryTaxonomy::NAME, ProductBrandTaxonomy::NAME], true)) {
            return null;
        }
        $term = get_term($termId, $taxonomy);

        return $term instanceof \WP_Term ? $term : null;
    }

    /**
     * The products in the term and the terms below it.
     *
     * @return list<int>
     */
    private static function products(int $termId, string $taxonomy): array
    {
        $children = get_term_children($termId, $taxonomy);
        $products = get_objects_in_term([$termId, ...(\is_array($children) ? self::ids($children) : [])], $taxonomy);

        return \is_array($products) ? self::ids($products) : [];
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
