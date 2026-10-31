<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Search\Application\Dto\SearchResult;

/**
 * What a Products block always lists, whatever the shopper chooses: the category, tag or brand it names or
 * whose page it is on, its featured or sale products, how many to a page and its own order. The Products block
 * lists them; its sort, count, pagination, active filters and filters blocks read the same, so they agree.
 *
 * @phpstan-import-type Choices from ShopListing
 */
final readonly class ListingScope
{
    public function __construct(
        /** The category's slug, the block's or the page's; '' for the shopper's. */
        public string $category = '',
        /** The block names the category: it has no links to the others. */
        public bool $ownCategory = false,
        public string $tag = '',
        public ?int $brand = null,
        public int $perPage = 12,
        public string $sort = 'order',
        public bool $featured = false,
        public bool $onSale = false,
        /** The Products block shows the filters itself. */
        public bool $filters = true,
        /** A block on the page counts the filters' values: searches made for it count them too, once. */
        public bool $facets = true,
    ) {}

    /**
     * A Products block's listing on the page shown: on a category's, tag's or brand's own page, a block that names
     * none lists that one's products.
     *
     * @param array<mixed> $attributes the block's
     * @param bool         $facets     another block on the page counts the filters' values
     */
    public static function of(array $attributes, bool $facets = false): self
    {
        $category = \is_string($attributes['category'] ?? null) ? sanitize_title($attributes['category']) : '';
        $tag = \is_string($attributes['tag'] ?? null) ? sanitize_title($attributes['tag']) : '';
        $own = $category !== '';
        $brand = null;
        $term = get_queried_object();
        if ($category === '' && $tag === '' && $term instanceof \WP_Term && is_tax()) {
            match ($term->taxonomy) {
                ProductCategoryTaxonomy::NAME => $category = $term->slug,
                ProductTagTaxonomy::NAME => $tag = $term->slug,
                ProductBrandTaxonomy::NAME => $brand = $term->term_id,
                default => null,
            };
        }
        $filters = ($attributes['filters'] ?? true) !== false;

        return new self(
            category: $category,
            ownCategory: $own,
            tag: $tag,
            brand: $brand,
            perPage: max(1, min(60, \is_int($attributes['perPage'] ?? null) ? $attributes['perPage'] : 12)),
            sort: \is_string($attributes['sort'] ?? null) ? $attributes['sort'] : 'order',
            featured: ($attributes['featured'] ?? false) === true,
            onSale: ($attributes['onSale'] ?? false) === true,
            filters: $filters,
            facets: $filters || $facets,
        );
    }

    /** The category, tag or brand is the block's or the page's, not the shopper's to change. */
    public function fixed(): bool
    {
        return $this->category !== '' || $this->tag !== '' || $this->brand !== null;
    }

    /**
     * The shopper's choices within it, from the address.
     *
     * @return Choices
     */
    public function choices(ShopListing $listing): array
    {
        return $listing->choices($this->category, $this->sort, $this->tag, $this->brand);
    }

    /**
     * A page of its products for the choices, and their total; with limit 0 only the total, which the page's
     * first search counted already (ShopListing keeps it for the request).
     *
     * @param Choices $choices
     * @param ?bool   $facets  count the filters' values (a block showing them); null when the page's blocks need them
     */
    public function search(ShopListing $listing, array $choices, int $offset = 0, int $limit = 0, ?bool $facets = null): SearchResult
    {
        return $listing->search($choices, $offset, $limit, $facets ?? $this->facets, $this->featured, $this->onSale);
    }
}
