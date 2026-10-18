<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Search\Application\Dto\Facet;
use OrillaCart\Search\Application\Dto\FacetCount;
use OrillaCart\Search\Application\Dto\OptionFilter;
use OrillaCart\Search\Application\Dto\SearchFilterView;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchResult;
use OrillaCart\Search\Application\Dto\SearchSort;
use OrillaCart\Search\Application\ShopSearch;
use OrillaCart\Search\Domain\Facets;

/**
 * What a shopper asks the shop page for, read from the address, as the Products and Product filters blocks
 * share it: words, category, brands, the shop's filters, price, stock, sort and page. The address is the only
 * state, so pages can be linked and work without the script; each form keeps, as hidden fields, the choices
 * the other one shows.
 *
 * @phpstan-type Choices array{category: string, category_id: ?int, search: string, brands: list<int>, options: array<string, list<string>>, price_min: ?string, price_max: ?string, in_stock: bool, sort: string, page: int}
 * @phpstan-type Panel array{filters: list<array{label: string, name: string, values: list<array{value: string, label: string, count: int, checked: bool}>}>, price: array{min: ?string, max: ?string, low: string, high: string}|null, in_stock: bool, filtered: bool, clear_url: string}
 */
final class ShopListing
{
    public const array SORTS = ['order', 'newest', 'name', 'price_asc', 'price_desc'];
    /** The address's arguments for the shop's filters; the rest are words, category, sort and page. */
    public const array FILTER_ARGUMENTS = ['brand', 'f', 'price_min', 'price_max', 'in_stock'];

    /** @var list<SearchFilterView>|null the shop's filters, read once per request */
    private ?array $definitions = null;

    public function __construct(private readonly ShopSearch $search) {}

    /**
     * @param string $fixedCategory a category slug the block always shows, instead of the address's
     *
     * @return Choices
     */
    public function choices(string $fixedCategory = '', string $defaultSort = 'order'): array
    {
        $category = $fixedCategory !== '' ? $fixedCategory : self::query('category');
        $categoryId = null;
        if ($category !== '') {
            $term = get_term_by('slug', $category, ProductCategoryTaxonomy::NAME);
            // An unknown category finds nothing rather than everything.
            $categoryId = $term instanceof \WP_Term ? $term->term_id : 0;
        }
        $search = self::query('q');
        $sorts = $search === '' ? self::SORTS : ['relevance', ...self::SORTS];
        $defaultSort = \in_array($defaultSort, self::SORTS, true) ? $defaultSort : 'order';

        return [
            'category' => $category,
            'category_id' => $categoryId,
            'search' => $search,
            'brands' => self::chosenIds('brand'),
            'options' => self::chosenOptions(),
            'price_min' => self::price('price_min'),
            'price_max' => self::price('price_max'),
            'in_stock' => self::query('in_stock') === '1',
            // Searching sorts by best match unless the shopper chose otherwise.
            'sort' => \in_array(self::query('sort'), $sorts, true) ? self::query('sort') : ($search === '' ? $defaultSort : 'relevance'),
            'page' => max(1, ctype_digit(self::query('product-page')) ? (int) self::query('product-page') : 1),
        ];
    }

    /**
     * The search for the choices: a page of products, and the filters' counts when $facets.
     *
     * @param Choices $choices
     */
    public function search(array $choices, int $offset, int $limit, bool $facets, bool $featuredOnly = false, bool $onSaleOnly = false): SearchResult
    {
        $options = $this->definitions();

        return $this->search->search(new SearchQuery(
            text: $choices['search'] === '' ? null : $choices['search'],
            categoryIds: $choices['category_id'] === null ? [] : [$choices['category_id']],
            brandIds: $choices['brands'],
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
        ));
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
        foreach ($this->definitions() as $filter) {
            $groups[] = ['label' => $filter->label, 'key' => $filter->key, 'name' => "f[{$filter->key}][]", 'chosen' => $choices['options'][$filter->key] ?? []];
        }
        $filters = [];
        foreach ($groups as $group) {
            $values = array_map(static fn(FacetCount $count): array => [
                'value' => $count->value,
                'label' => $count->label,
                'count' => $count->count,
                'checked' => \in_array($count->value, $group['chosen'], true),
            ], $result->facet($group['key'])->values ?? []);
            if ($values !== []) {
                $filters[] = ['label' => $group['label'], 'name' => $group['name'], 'values' => $values];
            }
        }

        return [
            'filters' => $filters,
            'price' => $result->priceRange === null ? null : ['min' => $choices['price_min'], 'max' => $choices['price_max'], 'low' => $result->priceRange[0], 'high' => $result->priceRange[1]],
            'in_stock' => $choices['in_stock'],
            'filtered' => $choices['brands'] !== [] || $choices['options'] !== [] || $choices['price_min'] !== null || $choices['price_max'] !== null || $choices['in_stock'],
            'clear_url' => remove_query_arg([...self::FILTER_ARGUMENTS, 'product-page']),
        ];
    }

    /**
     * The choices a form does not show, as hidden fields, so sending it keeps them.
     *
     * @param Choices $choices
     * @param bool    $filters the filters' choices (for the Products block's form without its panel); otherwise words, category and sort
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
        if (self::query('sort') !== '') {
            $fields[] = ['name' => 'sort', 'value' => $choices['sort']];
        }

        return $fields;
    }

    /**
     * @return list<SearchFilterView>
     */
    private function definitions(): array
    {
        return $this->definitions ??= $this->search->filters();
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
