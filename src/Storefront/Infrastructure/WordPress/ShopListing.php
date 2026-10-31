<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Search\Application\Dto\Facet;
use OrillaCart\Search\Application\Dto\FacetCount;
use OrillaCart\Search\Application\Dto\OptionFilter;
use OrillaCart\Search\Application\Dto\SearchFilterView;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchResult;
use OrillaCart\Search\Application\Dto\SearchSort;
use OrillaCart\Search\Application\SearchFilterTranslations;
use OrillaCart\Search\Application\ShopSearch;
use OrillaCart\Search\Domain\Facets;
use OrillaCart\Search\Infrastructure\WordPress\IndexChanges;
use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Storefront\Application\StorefrontSettingsService;
use OrillaCart\Translations\Application\ContentTranslations;

/**
 * What a shopper asks the shop page for, read from the address, as the Products block and those around it
 * share it: words, category, tag, brands, the shop's filters, price, stock, sort and page. The address is the only
 * state, so pages can be linked and work without the script; each form keeps, as hidden fields, the choices
 * the other one shows.
 *
 * @phpstan-type Choices array{category: string, category_id: ?int, tag: string, tag_id: ?int, search: string, brands: list<int>, options: array<string, list<string>>, price_min: ?string, price_max: ?string, in_stock: bool, sort: string, page: int, view: string}
 * @phpstan-type Panel array{filters: list<array{label: string, name: string, values: list<array{value: string, label: string, count: int, checked: bool}>}>, price: array{min: ?string, max: ?string, low: string, high: string}|null, in_stock: bool, filtered: bool, clear_url: string}
 */
final readonly class ShopListing
{
    public const array SORTS = ['order', 'newest', 'name', 'price_asc', 'price_desc'];
    /** The address's arguments for the shop's filters; the rest are words, category, tag, sort and page. */
    public const array FILTER_ARGUMENTS = ['brand', 'f', 'price_min', 'price_max', 'in_stock'];
    /** The page of products where the site has no pretty permalinks; older links that the shop sends on. */
    public const string PAGE_ARGUMENT = 'product-page';
    private const string CACHE_GROUP = 'orillacart_request';

    public function __construct(
        private ShopSearch $search,
        private StorefrontSettingsService $settings,
        private ContentLanguages $languages,
        private ContentTranslations $translations,
    ) {}

    /**
     * @param string $fixedCategory a category slug the block always shows, instead of the address's
     *
     * @return Choices
     */
    public function choices(string $fixedCategory = '', string $defaultSort = 'order', string $fixedTag = '', ?int $fixedBrand = null): array
    {
        $category = $fixedCategory !== '' ? $fixedCategory : self::query('category');
        $tag = $fixedTag !== '' ? $fixedTag : self::query('tag');
        $search = self::query('q');
        $sorts = $search === '' ? self::SORTS : ['relevance', ...self::SORTS];
        $defaultSort = \in_array($defaultSort, self::SORTS, true) ? $defaultSort : 'order';

        return [
            'category' => $category,
            'category_id' => self::termId($category, ProductCategoryTaxonomy::NAME),
            'tag' => $tag,
            'tag_id' => self::termId($tag, ProductTagTaxonomy::NAME),
            'search' => $search,
            'brands' => $fixedBrand !== null ? [$fixedBrand] : self::chosenIds('brand'),
            'options' => self::chosenOptions(),
            'price_min' => self::price('price_min'),
            'price_max' => self::price('price_max'),
            'in_stock' => self::query('in_stock') === '1',
            // Searching sorts by best match unless the shopper chose otherwise.
            'sort' => \in_array(self::query('sort'), $sorts, true) ? self::query('sort') : ($search === '' ? $defaultSort : 'relevance'),
            'page' => self::currentPage(),
            // The shopper's own way to see the products, grid or list; empty for the shop's.
            'view' => \in_array(self::query('view'), ['grid', 'list'], true) ? self::query('view') : '',
        ];
    }

    /**
     * The search for the choices: a page of products, and the filters' counts when $facets.
     *
     * @param Choices $choices
     */
    public function search(array $choices, int $offset, int $limit, bool $facets, bool $featuredOnly = false, bool $onSaleOnly = false): SearchResult
    {
        $options = $this->definitions($choices['category_id']);
        $query = new SearchQuery(
            text: $choices['search'] === '' ? null : $choices['search'],
            categoryIds: $choices['category_id'] === null ? [] : $this->everyLanguage($choices['category_id'], ProductCategoryTaxonomy::NAME),
            brandIds: array_values(array_unique(array_merge(...array_map(fn(int $id): array => $this->everyLanguage($id, ProductBrandTaxonomy::NAME), $choices['brands'])))),
            tagIds: $choices['tag_id'] === null ? [] : $this->everyLanguage($choices['tag_id'], ProductTagTaxonomy::NAME),
            options: array_map(static fn(SearchFilterView $filter): OptionFilter => new OptionFilter($filter->key, $filter->attributes, $choices['options'][$filter->key] ?? []), $options),
            priceMin: $choices['price_min'],
            priceMax: $choices['price_max'],
            inStockOnly: $choices['in_stock'],
            featuredOnly: $featuredOnly,
            onSaleOnly: $onSaleOnly,
            sort: SearchSort::from($choices['sort']),
            offset: $offset,
            limit: $limit,
            facets: $facets,
            untranslated: $this->settings->current()->listUntranslated,
        );

        // The Products and Product filters blocks of a page search for the same choices: the matches are
        // counted, and the filters' counts made, once; the other search only fetches its page of them.
        $key = 'shop_listing:' . md5(serialize([$query->matching(), $this->languages->current()?->locale, IndexChanges::count()]));
        wp_cache_add_non_persistent_groups([self::CACHE_GROUP]);
        $known = wp_cache_get($key, self::CACHE_GROUP);
        if (\is_array($known) && $known['result'] instanceof SearchResult && ($known['facets'] === true || !$facets)) {
            $ids = $limit < 1 ? [] : $this->search->search($query->pageOnly())->ids;

            return $facets ? new SearchResult($ids, $known['result']->total, $known['result']->facets, $known['result']->priceRange) : new SearchResult($ids, $known['result']->total);
        }
        $result = $this->search->search($query);
        wp_cache_set($key, ['result' => $result, 'facets' => $facets], self::CACHE_GROUP);

        return $result;
    }

    /**
     * The filter panel: brands and the shop's filters with the values the other choices leave and how many
     * products each would show (chosen values stay listed, to be unchosen), the price range, and stock.
     *
     * @param Choices $choices
     *
     * @return Panel
     */
    public function panel(array $choices, SearchResult $result): array
    {
        $groups = [['label' => __('Brand', 'orillacart'), 'key' => Facet::BRANDS, 'name' => 'brand[]', 'chosen' => array_map(strval(...), $choices['brands'])]];
        $definitions = $this->definitions($choices['category_id']);
        // The labels in the shopper's language, where the shop translated them.
        $labels = $this->translations->translated(SearchFilterTranslations::TYPE, array_map(static fn(SearchFilterView $filter): int => $filter->id, $definitions), 'label');
        foreach ($definitions as $filter) {
            $groups[] = ['label' => $labels[$filter->id] ?? $filter->label, 'key' => $filter->key, 'name' => "f[{$filter->key}][]", 'chosen' => $choices['options'][$filter->key] ?? []];
        }
        $filters = [];
        $hideSingle = $this->settings->current()->hideSingleValueFilters;
        foreach ($groups as $group) {
            $values = array_map(static fn(FacetCount $count): array => [
                'value' => $count->value,
                'label' => $count->label,
                'count' => $count->count,
                'checked' => \in_array($count->value, $group['chosen'], true),
            ], $result->facet($group['key'])->values ?? []);
            // One value narrows nothing, unless the shopper chose it and may unchoose it.
            $narrows = \count($values) > 1 || !$hideSingle || $group['chosen'] !== [];
            if ($values !== [] && $narrows) {
                $filters[] = ['label' => $group['label'], 'name' => $group['name'], 'values' => $values];
            }
        }

        return [
            'filters' => $filters,
            'price' => $result->priceRange === null ? null : ['min' => $choices['price_min'], 'max' => $choices['price_max'], 'low' => $result->priceRange[0], 'high' => $result->priceRange[1]],
            'in_stock' => $choices['in_stock'],
            'filtered' => $choices['brands'] !== [] || $choices['options'] !== [] || $choices['price_min'] !== null || $choices['price_max'] !== null || $choices['in_stock'],
            'clear_url' => remove_query_arg(self::FILTER_ARGUMENTS, self::pageUrl(1)),
        ];
    }

    /**
     * The choices a form does not show, as hidden fields, so sending it keeps them.
     *
     * @param Choices $choices
     * @param bool    $filters the filters' choices (for the Products block's form without its panel); otherwise words, category, tag, sort and view
     * @param bool    $category keep the category and tag (not when the Products block always shows its own)
     *
     * @return list<array{name: string, value: string}>
     */
    public function hidden(array $choices, bool $filters, bool $category = true): array
    {
        $fields = [];
        if ($filters) {
            foreach ($choices['brands'] as $brand) {
                $fields[] = ['name' => 'brand[]', 'value' => (string) $brand];
            }
            foreach ($choices['options'] as $key => $values) {
                foreach ($values as $value) {
                    $fields[] = ['name' => "f[{$key}][]", 'value' => $value];
                }
            }
            foreach (['price_min', 'price_max'] as $name) {
                if ($choices[$name] !== null) {
                    $fields[] = ['name' => $name, 'value' => $choices[$name]];
                }
            }
            if ($choices['in_stock']) {
                $fields[] = ['name' => 'in_stock', 'value' => '1'];
            }

            return $fields;
        }
        if ($choices['search'] !== '') {
            $fields[] = ['name' => 'q', 'value' => $choices['search']];
        }
        if ($category && $choices['category'] !== '') {
            $fields[] = ['name' => 'category', 'value' => $choices['category']];
        }
        if ($category && $choices['tag'] !== '') {
            $fields[] = ['name' => 'tag', 'value' => $choices['tag']];
        }
        if (self::query('sort') !== '') {
            $fields[] = ['name' => 'sort', 'value' => $choices['sort']];
        }
        if ($choices['view'] !== '') {
            $fields[] = ['name' => 'view', 'value' => $choices['view']];
        }

        return $fields;
    }

    /**
     * The orders a shopper may sort by, with their labels; best match only when searching.
     *
     * @return array<string, string> by sort
     */
    public static function sortLabels(bool $searching): array
    {
        return ($searching ? ['relevance' => __('Best match', 'orillacart')] : []) + [
            'order' => __('Recommended', 'orillacart'),
            'newest' => __('Newest', 'orillacart'),
            'name' => __('Name', 'orillacart'),
            'price_asc' => __('Price: low to high', 'orillacart'),
            'price_desc' => __('Price: high to low', 'orillacart'),
        ];
    }

    /** Where the listing's forms send their choices: its first page, without the choices they hold. */
    public static function formUrl(): string
    {
        return remove_query_arg(['category', 'tag', 'q', 'sort', ...self::FILTER_ARGUMENTS], self::pageUrl(1));
    }

    /**
     * @return list<SearchFilterView>
     */
    private function definitions(?int $categoryId): array
    {
        if ($categoryId === null || $categoryId === 0) {
            return $this->search->filters();
        }

        return $this->search->filters($categoryId, array_values(array_map(intval(...), get_ancestors($categoryId, ProductCategoryTaxonomy::NAME, 'taxonomy'))));
    }

    /**
     * The page of products shown: from the address's path (/page/2/ on a category, /shop/2/ on a page), or from
     * ?product-page=2 where the site has no pretty permalinks.
     */
    public static function currentPage(): int
    {
        $page = ctype_digit(self::query(self::PAGE_ARGUMENT)) ? (int) self::query(self::PAGE_ARGUMENT) : 1;
        foreach (['paged', 'page'] as $var) {
            $value = get_query_var($var);
            $page = max($page, is_numeric($value) ? (int) $value : 1);
        }

        return max(1, $page);
    }

    /**
     * The address of a page of the products shown, with the shopper's choices: /product-category/lamps/page/2/,
     * /shop/2/, or ?product-page=2 without pretty permalinks.
     */
    public static function pageUrl(int $number): string
    {
        $pretty = get_option('permalink_structure') !== '';
        $page = is_singular() && !is_front_page() ? get_queried_object() : null;
        if (!$pretty) {
            $url = remove_query_arg([self::PAGE_ARGUMENT, 'paged', 'page']);

            return $number > 1 ? add_query_arg(self::PAGE_ARGUMENT, $number, $url) : $url;
        }
        $term = is_tax() ? get_queried_object() : null;
        if ($page instanceof \WP_Post) {
            $link = (string) get_permalink($page);
            $base = $number > 1 ? user_trailingslashit(trailingslashit($link) . $number, 'single_paged') : $link;
        } elseif ($term instanceof \WP_Term) {
            $link = get_term_link($term);
            $link = \is_string($link) ? $link : home_url('/');
            $rewrite = $GLOBALS['wp_rewrite'] ?? null;
            $paging = $rewrite instanceof \WP_Rewrite ? $rewrite->pagination_base : 'page';
            $base = $number > 1 ? user_trailingslashit(trailingslashit($link) . $paging . '/' . $number, 'paged') : $link;
        } else {
            // WordPress's own paging reads the request, which a command line has not.
            $base = isset($_SERVER['REQUEST_URI']) ? (string) strtok(get_pagenum_link($number, false), '?') : home_url('/');
        }
        $query = [];
        parse_str((string) wp_parse_url(self::requestUri(), \PHP_URL_QUERY), $query);
        unset($query[self::PAGE_ARGUMENT], $query['paged'], $query['page']);

        return $query === [] ? $base : $base . '?' . http_build_query($query);
    }

    private static function requestUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return \is_string($uri) ? wp_unslash($uri) : '';
    }

    /**
     * The term with its translations: the index holds each product's terms in the product's own language, which
     * need not be the page's (design 51).
     *
     * @return list<int>
     */
    private function everyLanguage(int $termId, string $taxonomy): array
    {
        return array_values(array_unique([$termId, ...array_values($this->languages->termTranslations($termId, $taxonomy))]));
    }

    /** The id of the term with the slug; an unknown one is 0, which finds nothing rather than everything. */
    private static function termId(string $slug, string $taxonomy): ?int
    {
        if ($slug === '') {
            return null;
        }
        $term = get_term_by('slug', $slug, $taxonomy);

        return $term instanceof \WP_Term ? $term->term_id : 0;
    }

    private static function query(string $name): string
    {
        $value = $_GET[$name] ?? '';

        return \is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
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
