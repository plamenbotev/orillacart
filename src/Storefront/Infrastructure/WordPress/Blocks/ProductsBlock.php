<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\ProductCategories;
use OrillaCart\Search\Application\Dto\Facet;
use OrillaCart\Search\Application\Dto\FacetCount;
use OrillaCart\Search\Application\Dto\OptionFilter;
use OrillaCart\Search\Application\Dto\SearchFilterView;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchResult;
use OrillaCart\Search\Application\Dto\SearchSort;
use OrillaCart\Search\Application\ShopSearch;
use OrillaCart\Search\Domain\Facets;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;

/**
 * The shop: published products in a grid, which visitors narrow by category, words, brand, the shop's filters,
 * price and stock, and sort. The products and the counts come from the search index; their cards show the
 * catalog's offers as they are now. The query string carries the choices (q, category, brand[], f[size][],
 * price_min, price_max, in_stock, sort, product-page), so pages can be linked and work without the script.
 */
final readonly class ProductsBlock implements BlockDefinition
{
    public const array SORTS = ['order', 'newest', 'name', 'price_asc', 'price_desc'];

    public function __construct(
        private ShopCatalog $catalog,
        private ShopSearch $search,
        private ProductCategories $categories,
        private ShopLinks $links,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/products';
    }

    public static function metadata(): array
    {
        return [
            'title' => __('Products', 'orillacart'),
            'description' => __('The shop: products with their prices, by category, with search.', 'orillacart'),
            'icon' => 'products',
            'keywords' => [__('shop', 'orillacart'), __('catalog', 'orillacart')],
            'attributes' => [
                'perPage' => ['type' => 'integer', 'default' => 12],
                'columns' => ['type' => 'integer', 'default' => 3],
                'category' => ['type' => 'string', 'default' => ''],
                'sort' => ['type' => 'string', 'default' => 'order'],
                'featured' => ['type' => 'boolean', 'default' => false],
                'onSale' => ['type' => 'boolean', 'default' => false],
                'filters' => ['type' => 'boolean', 'default' => true],
            ],
        ];
    }

    public function render(array $attributes): string
    {
        $perPage = max(1, min(60, \is_int($attributes['perPage'] ?? null) ? $attributes['perPage'] : 12));
        $columns = max(1, min(6, \is_int($attributes['columns'] ?? null) ? $attributes['columns'] : 3));
        $fixedCategory = \is_string($attributes['category'] ?? null) ? sanitize_title($attributes['category']) : '';

        $category = $fixedCategory !== '' ? $fixedCategory : self::query('category');
        $search = self::query('q');
        $defaultSort = \is_string($attributes['sort'] ?? null) && \in_array($attributes['sort'], self::SORTS, true) ? $attributes['sort'] : 'order';
        $sorts = $search === '' ? self::SORTS : ['relevance', ...self::SORTS];
        // Searching sorts by best match unless the shopper chose otherwise.
        $sort = \in_array(self::query('sort'), $sorts, true) ? self::query('sort') : ($search === '' ? $defaultSort : 'relevance');
        $page = max(1, ctype_digit(self::query('product-page')) ? (int) self::query('product-page') : 1);

        $categoryId = null;
        if ($category !== '') {
            $term = get_term_by('slug', $category, ProductCategoryTaxonomy::NAME);
            $categoryId = $term instanceof \WP_Term ? $term->term_id : 0;
        }
        $showFilters = ($attributes['filters'] ?? true) !== false;
        $definitions = $showFilters ? $this->search->filters() : [];
        $chosen = self::chosenOptions();
        $brands = self::chosenIds('brand');
        $priceMin = self::price('price_min');
        $priceMax = self::price('price_max');
        $inStock = self::query('in_stock') === '1';
        $result = $this->search->search(new SearchQuery(
            text: $search === '' ? null : $search,
            // An unknown category finds nothing rather than everything.
            categoryIds: $categoryId === null ? [] : [$categoryId],
            brandIds: $brands,
            options: array_map(static fn(SearchFilterView $filter): OptionFilter => new OptionFilter($filter->key, $filter->attributes, $chosen[$filter->key] ?? []), $definitions),
            priceMin: $priceMin,
            priceMax: $priceMax,
            inStockOnly: $inStock,
            featuredOnly: ($attributes['featured'] ?? false) === true,
            onSaleOnly: ($attributes['onSale'] ?? false) === true,
            sort: SearchSort::from($sort),
            offset: ($page - 1) * $perPage,
            limit: $perPage,
            facets: $showFilters,
        ));
        $ids = $result->ids;
        $offers = $this->catalog->offers($ids);
        $urls = $this->links->products($ids);

        $products = [];
        foreach ($offers as $id => $offer) {
            $products[] = [
                'offer' => $offer,
                'simple' => $offer->isSimple(),
                'url' => $urls[$id] ?? '',
                'image' => get_the_post_thumbnail($id, 'medium', ['class' => 'orillacart-product-card__image', 'loading' => 'lazy']),
            ];
        }

        $base = self::baseUrl();
        $pages = (int) ceil($result->total / $perPage);
        $pageUrls = [];
        for ($number = 1; $number <= $pages; ++$number) {
            $pageUrls[$number] = $number === 1 ? remove_query_arg('product-page') : add_query_arg('product-page', $number);
        }

        return $this->templates->render('storefront/products.html.twig', [
            'products' => $products,
            'categories' => $fixedCategory !== '' ? [] : array_map(static fn(array $term): array => $term + [
                'url' => add_query_arg('category', $term['slug'], $base),
                'current' => $term['slug'] === $category,
            ], $this->categories->ordered()),
            'current_category' => $fixedCategory !== '' ? '' : $category,
            'all_url' => $base,
            'base_url' => $base,
            'search' => $search,
            'sort' => $sort,
            'sorts' => ($search === '' ? [] : ['relevance' => __('Best match', 'orillacart')]) + [
                'order' => __('Recommended', 'orillacart'),
                'newest' => __('Newest', 'orillacart'),
                'name' => __('Name', 'orillacart'),
                'price_asc' => __('Price: low to high', 'orillacart'),
                'price_desc' => __('Price: high to low', 'orillacart'),
            ],
            'page' => $page,
            'pages' => $pages,
            'page_urls' => $pageUrls,
            'columns' => $columns,
            'cart_url' => $this->links->pages()['cart'],
            'filters' => $showFilters ? self::facets($result, $definitions, $chosen, $brands) : [],
            'price' => $showFilters && $result->priceRange !== null ? ['min' => $priceMin, 'max' => $priceMax, 'low' => $result->priceRange[0], 'high' => $result->priceRange[1]] : null,
            'in_stock' => $inStock,
            'show_filters' => $showFilters,
            'filtered' => $brands !== [] || $chosen !== [] || $priceMin !== null || $priceMax !== null || $inStock,
            'clear_url' => remove_query_arg(['brand', 'f', 'price_min', 'price_max', 'in_stock', 'product-page']),
        ]);
    }

    private static function query(string $name): string
    {
        $value = $_GET[$name] ?? '';

        return \is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }

    /** This page without the shop's query arguments. */
    private static function baseUrl(): string
    {
        return remove_query_arg(['category', 'q', 'sort', 'product-page', 'brand', 'f', 'price_min', 'price_max', 'in_stock']);
    }

    /**
     * The brands and the shop's filters with the values the other choices leave, and how many products each
     * would show. Chosen values stay listed, so they can be unchosen.
     *
     * @param list<SearchFilterView>      $definitions
     * @param array<string, list<string>> $chosen
     * @param list<int>                   $brands
     *
     * @return list<array{label: string, name: string, values: list<array{value: string, label: string, count: int, checked: bool}>}>
     */
    private static function facets(SearchResult $result, array $definitions, array $chosen, array $brands): array
    {
        $groups = [['label' => __('Brand', 'orillacart'), 'key' => Facet::BRANDS, 'name' => 'brand[]', 'chosen' => array_map(strval(...), $brands)]];
        foreach ($definitions as $filter) {
            $groups[] = ['label' => $filter->label, 'key' => $filter->key, 'name' => "f[{$filter->key}][]", 'chosen' => $chosen[$filter->key] ?? []];
        }
        $facets = [];
        foreach ($groups as $group) {
            $values = array_map(static fn(FacetCount $count): array => [
                'value' => $count->value,
                'label' => $count->label,
                'count' => $count->count,
                'checked' => \in_array($count->value, $group['chosen'], true),
            ], $result->facet($group['key'])->values ?? []);
            if ($values !== []) {
                $facets[] = ['label' => $group['label'], 'name' => $group['name'], 'values' => $values];
            }
        }

        return $facets;
    }

    /**
     * The values chosen in the shop's filters: ?f[size][]=m&f[size][]=l, as the index keeps them.
     *
     * @return array<string, list<string>> by filter key
     */
    private static function chosenOptions(): array
    {
        $given = $_GET['f'] ?? null;
        if (!\is_array($given)) {
            return [];
        }
        $chosen = [];
        foreach ($given as $key => $values) {
            if (!\is_string($key) || preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $key) !== 1 || !\is_array($values)) {
                continue;
            }
            $picked = array_values(array_unique(array_map(static fn(string $value): string => Facets::value(sanitize_text_field(wp_unslash($value))), array_filter($values, \is_string(...)))));
            $picked = array_values(array_filter($picked, static fn(string $value): bool => $value !== ''));
            if ($picked !== []) {
                $chosen[$key] = \array_slice($picked, 0, 50);
            }
        }

        return $chosen;
    }

    /**
     * @return list<int>
     */
    private static function chosenIds(string $name): array
    {
        $given = $_GET[$name] ?? null;

        return \is_array($given) ? array_values(array_unique(array_map(intval(...), array_filter($given, static fn(mixed $id): bool => \is_string($id) && ctype_digit($id))))) : [];
    }

    private static function price(string $name): ?string
    {
        $value = self::query($name);

        return preg_match('/^\d{1,9}(\.\d{1,4})?$/', $value) === 1 ? $value : null;
    }
}
