<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Queues the products of a category, brand or tag that was renamed, moved or deleted: the index keeps their names,
 * and a product is found under every category above its own. A change to anything else, such as the
 * description, queues nothing. A deleted category's products, its subcategories' too, are found before it goes,
 * while they still hang below it, and queued once the row is gone. On a site in several languages a term's
 * translations' products too (design 51): their documents carry the term's name in each language, and a term made
 * or linked as a translation is a new name for them.
 */
#[AsHook('edit_terms', method: 'editing', arguments: 2)]
#[AsHook('edited_term', method: 'edited', arguments: 3)]
#[AsHook('created_term', method: 'created', arguments: 3)]
#[AsHook('pre_delete_term', method: 'deleting', arguments: 2)]
#[AsHook('delete_term', method: 'deleted', arguments: 3)]
#[AsHook('pll_save_term_translations', method: 'linked')]
#[AsHook('wpml_translation_update', method: 'wpmlChanged')]
final class QueueTermProducts
{
    private const array TAXONOMIES = [ProductCategoryTaxonomy::NAME, ProductBrandTaxonomy::NAME, ProductTagTaxonomy::NAME];

    /** @var array<int, array{string, int}> name and parent of the terms being edited, by term id */
    private array $before = [];

    /** @var array<int, list<int>> the products of the terms being deleted, by term id */
    private array $deleting = [];

    public function __construct(
        private readonly SearchIndexing $indexing,
        private readonly ContentLanguages $languages,
    ) {}

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
            $this->indexing->queue($this->products($termId, $taxonomy));
        }
    }

    /** A new term has no products yet; made as a translation, it is a new name for its translations' products. */
    public function created(int $termId, int|string $termTaxonomyId, string $taxonomy): void
    {
        if (self::shopTerm($termId, $taxonomy) !== null && $this->languages->multilingual()) {
            $this->indexing->queue($this->products($termId, $taxonomy));
        }
    }

    public function deleting(int $termId, string $taxonomy): void
    {
        if (self::shopTerm($termId, $taxonomy) !== null) {
            $this->deleting[$termId] = $this->products($termId, $taxonomy);
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

    /**
     * Polylang linked terms as translations of each other.
     *
     * @param mixed $translations term id by language code
     */
    public function linked(mixed $translations): void
    {
        $products = [];
        foreach (\is_array($translations) ? $translations : [] as $id) {
            $term = is_numeric($id) ? get_term((int) $id) : null;
            if ($term instanceof \WP_Term && \in_array($term->taxonomy, self::TAXONOMIES, true)) {
                $products = [...$products, ...$this->products($term->term_id, $term->taxonomy)];
            }
        }
        if ($products !== []) {
            $this->indexing->queue(array_values(array_unique($products)));
        }
    }

    /**
     * WPML saved, linked or removed a term's translation (wpml_translation_update, "tax_" and the taxonomy).
     *
     * @param mixed $args array{type?: string, element_id?: int|string, element_type?: string}
     */
    public function wpmlChanged(mixed $args): void
    {
        $id = \is_array($args) && is_numeric($args['element_id'] ?? null) ? (int) $args['element_id'] : 0;
        $type = \is_array($args) && \is_string($args['element_type'] ?? null) ? $args['element_type'] : '';
        foreach (self::TAXONOMIES as $taxonomy) {
            if ($id > 0 && $type === 'tax_' . $taxonomy && self::shopTerm($id, $taxonomy) !== null) {
                $this->indexing->queue($this->products($id, $taxonomy));
            }
        }
    }

    private static function shopTerm(int $termId, string $taxonomy): ?\WP_Term
    {
        if (!\in_array($taxonomy, self::TAXONOMIES, true)) {
            return null;
        }
        $term = get_term($termId, $taxonomy);

        return $term instanceof \WP_Term ? $term : null;
    }

    /**
     * The products in the term and the terms below it, and in each of its translations and theirs.
     *
     * @return list<int>
     */
    private function products(int $termId, string $taxonomy): array
    {
        $terms = [];
        foreach (array_unique([$termId, ...array_values($this->languages->termTranslations($termId, $taxonomy))]) as $id) {
            $children = get_term_children($id, $taxonomy);
            $terms = [...$terms, $id, ...(\is_array($children) ? self::ids($children) : [])];
        }
        $products = get_objects_in_term(array_values(array_unique($terms)), $taxonomy);

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
