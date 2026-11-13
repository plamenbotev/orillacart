<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Extension\Catalog\Catalog;
use OrillaCart\Extension\Catalog\CatalogFacet;
use OrillaCart\Extension\Catalog\CatalogPage;
use OrillaCart\Extension\Catalog\CatalogProduct;
use OrillaCart\Extension\Catalog\CatalogQuery;
use OrillaCart\Search\Application\Dto\Facet;
use OrillaCart\Search\Application\Dto\FacetCount;
use OrillaCart\Search\Application\Dto\SearchQuery;

/**
 * The shop's listing for add-ons (design 74, API 29): the same search, filters, counts and cards as the Products
 * block and the shop page, with the choices from the add-on instead of the address. What it finds is what a
 * shopper may find: published, not behind a password, priced as ProductCards prices it for them.
 *
 * @phpstan-import-type Choices from ShopListing
 */
final readonly class AddOnCatalog implements Catalog
{
    public function __construct(
        private ShopListing $listing,
        private ProductCards $cards,
        private RenderedProductCards $html,
    ) {}

    public function list(CatalogQuery $query): CatalogPage
    {
        $perPage = max(1, min(CatalogQuery::MOST_PER_PAGE, $query->perPage));
        $page = max(1, min(ShopListing::MAX_PAGE, $query->page));
        $choices = self::choices($query, $page);
        $result = $this->listing->search($choices, ($page - 1) * $perPage, $perPage, $query->facets, $query->featured, $query->onSale);
        $cards = $this->cards->cards($result->ids);
        $products = [];
        foreach ($cards as $card) {
            $offer = $card['offer'];
            if ($offer instanceof ProductOffer) {
                $products[] = self::product($offer, \is_string($card['url'] ?? null) ? $card['url'] : '');
            }
        }

        return new CatalogPage(
            $products,
            $result->total,
            $page,
            max(1, (int) ceil($result->total / $perPage)),
            $perPage,
            $query->facets ? $this->facets($choices, $result) : [],
            $query->facets ? $result->priceRange : null,
            $query->cards ? $this->html->html($cards) : null,
            ShopListing::sortLabels($choices['search'] !== ''),
        );
    }

    /**
     * The choices as the shop page reads them from its address.
     *
     * @return Choices
     */
    private static function choices(CatalogQuery $query, int $page): array
    {
        $words = SearchQuery::words($query->words ?? '');
        $sorts = $words === '' ? ShopListing::SORTS : ['relevance', ...ShopListing::SORTS];
        $options = [];
        foreach ($query->filters as $key => $values) {
            $values = array_values(array_filter($values, static fn(mixed $value): bool => \is_string($value) && $value !== ''));
            if ($values !== []) {
                $options[$key] = $values;
            }
        }

        return [
            'category' => '',
            'category_id' => $query->categoryId,
            'tag' => '',
            'tag_id' => $query->tagId,
            'search' => $words,
            'brands' => \array_slice(array_values(array_filter($query->brandIds, static fn(int $id): bool => $id > 0)), 0, SearchQuery::MAX_BRANDS),
            'options' => $options,
            'price_min' => self::amount($query->priceMin),
            'price_max' => self::amount($query->priceMax),
            'in_stock' => $query->inStock,
            'sort' => \in_array($query->sort, $sorts, true) ? $query->sort : ($words === '' ? 'order' : 'relevance'),
            'page' => $page,
            'view' => '',
        ];
    }

    private static function amount(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{1,9}(\.\d{1,4})?$/', $value) === 1 ? $value : null;
    }

    private static function product(ProductOffer $offer, string $url): CatalogProduct
    {
        $image = null;
        $picture = (int) get_post_thumbnail_id($offer->id);
        $source = $picture > 0 ? wp_get_attachment_image_src($picture, 'medium') : false;
        if ($source !== false) {
            $alt = get_post_meta($picture, '_wp_attachment_image_alt', true);
            $image = ['url' => $source[0], 'alt' => \is_string($alt) ? $alt : '', 'width' => $source[1], 'height' => $source[2]];
        }

        return new CatalogProduct(
            $offer->id,
            $offer->name,
            $offer->type,
            $url,
            $image,
            $offer->priceHidden ? null : $offer->price,
            $offer->priceHidden ? null : $offer->regularPrice,
            $offer->priceHidden ? null : $offer->highestPrice,
            $offer->shownWithTax ?? false,
            $offer->forSale,
            !$offer->soldOut,
            $offer->onBackorder,
            $offer->featured,
        );
    }

    /**
     * Categories, then brands and the shop's filters as the filter panel names them.
     *
     * @param Choices $choices
     *
     * @return list<CatalogFacet>
     */
    private function facets(array $choices, \OrillaCart\Search\Application\Dto\SearchResult $result): array
    {
        $facets = [];
        $categories = $result->facet(Facet::CATEGORIES);
        if ($categories !== null && $categories->values !== []) {
            $facets[] = new CatalogFacet(Facet::CATEGORIES, __('Category', 'orillacart'), array_map(static fn(FacetCount $count): array => ['value' => $count->value, 'label' => $count->label, 'count' => $count->count, 'chosen' => $count->value === (string) $choices['category_id']], $categories->values));
        }
        $panel = $this->listing->panel($choices, $result);
        foreach ($panel['filters'] as $filter) {
            // The panel names a filter by its form field: brand[] or f[key][].
            $key = $filter['name'] === 'brand[]' ? Facet::BRANDS : (preg_match('/^f\[([^\]]+)\]\[\]$/', $filter['name'], $match) === 1 ? $match[1] : $filter['name']);
            $facets[] = new CatalogFacet($key, $filter['label'], array_map(static fn(array $value): array => ['value' => $value['value'], 'label' => $value['label'], 'count' => $value['count'], 'chosen' => $value['checked']], $filter['values']));
        }

        return $facets;
    }
}
