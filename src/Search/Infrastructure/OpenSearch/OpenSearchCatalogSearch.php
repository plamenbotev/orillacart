<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\OpenSearch;

use OrillaCart\Search\Application\CatalogSearch;
use OrillaCart\Search\Application\Dto\Facet;
use OrillaCart\Search\Application\Dto\FacetCount;
use OrillaCart\Search\Application\Dto\OptionFilter;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchResult;
use OrillaCart\Search\Application\Dto\SearchSort;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Domain\Facets;
use OrillaCart\Search\Infrastructure\Doctrine\ValueLabels;
use OrillaCart\Shared\Lifecycle\Languages;

/**
 * Searches the shop's OpenSearch index, answering as the database engine does: words (with typos forgiven),
 * categories and brands on the product; options, price and stock on one nested variant; and counts for each
 * facet with every other choice applied, in global aggregations. In a language, by the words in it.
 */
final readonly class OpenSearchCatalogSearch implements CatalogSearch
{
    private const string PRICE = "\0price";
    private const string SEP = OpenSearchIndex::SEPARATOR;

    /** A variant's option values under the filter's attribute names. */
    private const string VALUES_SCRIPT = <<<'PAINLESS'
        List values = new ArrayList();
        for (def option : doc['variants.options']) {
            int at = option.indexOf(params.sep);
            if (at > 0 && params.facets.contains(option.substring(0, at))) {
                values.add(option.substring(at + 1));
            }
        }
        return values;
        PAINLESS;

    public function __construct(
        private OpenSearchClient $client,
        private EngineSettingsRepository $settings,
        private ValueLabels $labels,
        private Languages $locales,
    ) {}

    /**
     * @throws OpenSearchUnavailable
     */
    public function search(SearchQuery $query): SearchResult
    {
        $settings = $this->settings->load();
        $text = $query->text === null ? '' : trim($query->text);
        $body = [
            'from' => max(0, $query->offset),
            'size' => max(0, $query->limit),
            'track_total_hits' => true,
            '_source' => ['product_id'],
            'query' => ['bool' => [
                'must' => $text === '' ? [] : [$this->words($text, $query)],
                'filter' => $this->filters($query, null, true),
            ]],
            'sort' => self::sort($query->sort, $text !== ''),
        ];
        if ($query->facets) {
            $body['aggs'] = ['all' => ['global' => (object) [], 'aggs' => $this->aggregations($query, $text)]];
        }
        $answer = $this->client->request($settings, 'POST', "/{$settings->index}/_search", $body);

        $hits = self::at($answer, 'hits', 'hits');
        $ids = [];
        foreach (\is_array($hits) ? $hits : [] as $hit) {
            $id = self::at($hit, '_source', 'product_id');
            if (\is_int($id)) {
                $ids[] = $id;
            }
        }
        $total = self::at($answer, 'hits', 'total', 'value');
        if (!$query->facets) {
            return new SearchResult($ids, \is_int($total) ? $total : \count($ids));
        }
        $aggregations = self::at($answer, 'aggregations', 'all');

        return new SearchResult($ids, \is_int($total) ? $total : \count($ids), $this->facets($query, \is_array($aggregations) ? $aggregations : []), self::priceRange($aggregations));
    }

    /**
     * The words in the folded fields, and in the language's analyzed ones: a stem finds its other forms.
     *
     * @return array<string, mixed>
     */
    private function words(string $text, SearchQuery $query): array
    {
        $analyzer = OpenSearchLanguages::analyzer($query->locale ?? $this->locales->site());
        $fields = $analyzer === null ? ['title^3', 'text'] : ['title^3', 'text', "words.{$analyzer}.title^3", "words.{$analyzer}.text"];

        return ['bool' => [
            'should' => [
                ['multi_match' => ['query' => $text, 'fields' => $fields, 'operator' => 'and', 'fuzziness' => 'AUTO', 'prefix_length' => 1]],
                ['match_phrase_prefix' => ['title' => $text]],
                ['prefix' => ['skus' => ['value' => mb_strtolower($text)]]],
            ],
            'minimum_should_match' => 1,
        ]];
    }

    /**
     * The conditions on the product, and (unless left out) on one of its variants.
     *
     * @return list<array<string, mixed>>
     */
    private function filters(SearchQuery $query, ?string $except, bool $variants): array
    {
        $filters = [self::language($query)];
        if ($query->featuredOnly) {
            $filters[] = ['term' => ['featured' => true]];
        }
        if ($query->onSaleOnly) {
            $filters[] = ['term' => ['on_sale' => true]];
        }
        if ($query->categoryIds !== [] && $except !== Facet::CATEGORIES) {
            $filters[] = ['terms' => ['categories' => array_map(strval(...), $query->categoryIds)]];
        }
        if ($query->brandIds !== [] && $except !== Facet::BRANDS) {
            $filters[] = ['terms' => ['brands' => array_map(strval(...), $query->brandIds)]];
        }
        if ($query->tagIds !== []) {
            $filters[] = ['terms' => ['tags' => array_map(strval(...), $query->tagIds)]];
        }
        $variant = self::variantFilters($query, $except);
        if ($variants && $variant !== []) {
            $filters[] = ['nested' => ['path' => 'variants', 'query' => ['bool' => ['filter' => $variant]]]];
        }

        return $filters;
    }

    /**
     * One document of each product: in the language, its translation's, else its own (a translation's words are a
     * document of their own, the original lists the languages it has them in); without one, its own.
     *
     * @return array<string, mixed>
     */
    private static function language(SearchQuery $query): array
    {
        $original = ['bool' => ['must_not' => [['term' => ['translation' => true]]]]];
        if ($query->locale === null) {
            return $original;
        }
        $inLanguage = ['term' => ['locale' => $query->locale]];
        if (!$query->untranslated) {
            return $inLanguage;
        }

        return ['bool' => ['should' => [
            $inLanguage,
            ['bool' => ['must_not' => [['term' => ['translation' => true]], ['term' => ['translated' => $query->locale]]]]],
        ], 'minimum_should_match' => 1]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function variantFilters(SearchQuery $query, ?string $except): array
    {
        $filters = [];
        if ($query->inStockOnly) {
            $filters[] = ['term' => ['variants.in_stock' => true]];
        }
        if ($except !== self::PRICE && ($query->priceMin !== null || $query->priceMax !== null)) {
            $filters[] = ['range' => ['variants.price' => array_filter(['gte' => $query->priceMin === null ? null : (float) $query->priceMin, 'lte' => $query->priceMax === null ? null : (float) $query->priceMax], static fn(?float $bound): bool => $bound !== null)]];
        }
        foreach ($query->options as $filter) {
            if ($filter->values !== [] && $filter->key !== $except) {
                $filters[] = ['terms' => ['variants.options' => self::optionTerms($filter)]];
            }
        }

        return $filters;
    }

    /**
     * Every attribute name of the filter with every chosen value.
     *
     * @return list<string>
     */
    private static function optionTerms(OptionFilter $filter): array
    {
        $terms = [];
        foreach (self::facetNames($filter) as $facet) {
            foreach ($filter->values as $value) {
                $terms[] = $facet . self::SEP . Facets::value($value);
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregations(SearchQuery $query, string $text): array
    {
        $scoped = fn(?string $except, bool $variants): array => ['bool' => ['must' => $text === '' ? [] : [$this->words($text, $query)], 'filter' => $this->filters($query, $except, $variants)]];
        $aggregations = [
            Facet::CATEGORIES => ['filter' => $scoped(Facet::CATEGORIES, true), 'aggs' => ['values' => ['terms' => ['field' => 'categories', 'size' => 500]]]],
            Facet::BRANDS => ['filter' => $scoped(Facet::BRANDS, true), 'aggs' => ['values' => ['terms' => ['field' => 'brands', 'size' => 500]]]],
            'price' => ['filter' => $scoped(self::PRICE, false), 'aggs' => ['variants' => ['nested' => ['path' => 'variants'], 'aggs' => ['matching' => [
                'filter' => ['bool' => ['filter' => self::variantFilters($query, self::PRICE)]],
                'aggs' => ['low' => ['min' => ['field' => 'variants.price']], 'high' => ['max' => ['field' => 'variants.price']]],
            ]]]]],
        ];
        foreach ($query->options as $index => $filter) {
            $aggregations["option{$index}"] = ['filter' => $scoped($filter->key, false), 'aggs' => ['variants' => ['nested' => ['path' => 'variants'], 'aggs' => ['matching' => [
                'filter' => ['bool' => ['filter' => self::variantFilters($query, $filter->key)]],
                'aggs' => ['values' => [
                    // By value, whichever of the filter's attribute names holds it, counting each product once.
                    'terms' => ['size' => 500, 'script' => ['lang' => 'painless', 'source' => self::VALUES_SCRIPT, 'params' => ['sep' => self::SEP, 'facets' => self::facetNames($filter)]]],
                    'aggs' => ['products' => ['reverse_nested' => (object) []]],
                ]],
            ]]]]];
        }

        return $aggregations;
    }

    /**
     * @param array<mixed> $aggregations
     *
     * @return list<Facet>
     */
    private function facets(SearchQuery $query, array $aggregations): array
    {
        /** @var array<string, list<array{list<string>, string, int}>> $found facet names, value and count, by facet key */
        $found = [];
        foreach ([Facet::CATEGORIES => Facets::CATEGORY, Facet::BRANDS => Facets::BRAND] as $key => $facet) {
            $buckets = self::at($aggregations, $key, 'values', 'buckets');
            foreach (\is_array($buckets) ? $buckets : [] as $bucket) {
                $value = self::at($bucket, 'key');
                $count = self::at($bucket, 'doc_count');
                if (\is_string($value) && \is_int($count)) {
                    $found[$key][] = [[$facet], $value, $count];
                }
            }
        }
        foreach ($query->options as $index => $filter) {
            $buckets = self::at($aggregations, "option{$index}", 'variants', 'matching', 'values', 'buckets');
            $facetNames = self::facetNames($filter);
            foreach (\is_array($buckets) ? $buckets : [] as $bucket) {
                $value = self::at($bucket, 'key');
                $count = self::at($bucket, 'products', 'doc_count');
                if (\is_string($value) && \is_int($count)) {
                    $found[$filter->key][] = [$facetNames, $value, $count];
                }
            }
        }

        $pairs = [];
        foreach ($found as $rows) {
            foreach ($rows as [$facets, $value]) {
                foreach ($facets as $facet) {
                    $pairs[] = [$facet, $value];
                }
            }
        }
        $labels = $this->labels->of($pairs, $query->locale);
        $facets = [];
        foreach ([Facet::CATEGORIES, Facet::BRANDS, ...array_map(static fn(OptionFilter $filter): string => $filter->key, $query->options)] as $key) {
            $values = [];
            foreach ($found[$key] ?? [] as [$names, $value, $count]) {
                $values[] = new FacetCount($value, self::label($labels, $names, $value), $count);
            }
            usort($values, static fn(FacetCount $a, FacetCount $b): int => strcmp($a->label, $b->label));
            $facets[] = new Facet($key, $values);
        }

        return $facets;
    }

    /**
     * @return array{string, string}|null
     */
    private static function priceRange(mixed $aggregations): ?array
    {
        $low = self::at($aggregations, 'price', 'variants', 'matching', 'low', 'value');
        $high = self::at($aggregations, 'price', 'variants', 'matching', 'high', 'value');

        return is_numeric($low) && is_numeric($high) ? [self::decimal((float) $low), self::decimal((float) $high)] : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function sort(SearchSort $sort, bool $words): array
    {
        $shopOrder = [['menu_order' => 'asc'], ['title.sort' => 'asc'], ['product_id' => 'asc']];

        return match ($sort) {
            SearchSort::Order => $shopOrder,
            SearchSort::Relevance => $words ? [['_score' => 'desc'], ...$shopOrder] : $shopOrder,
            SearchSort::Newest => [['published_at' => 'desc'], ['product_id' => 'desc']],
            SearchSort::Name => [['title.sort' => 'asc'], ['product_id' => 'asc']],
            // Products whose price shoppers ask for come last either way.
            SearchSort::PriceAscending => [['price_min' => ['order' => 'asc', 'missing' => '_last']], ['title.sort' => 'asc'], ['product_id' => 'asc']],
            SearchSort::PriceDescending => [['price_max' => ['order' => 'desc', 'missing' => '_last']], ['title.sort' => 'asc'], ['product_id' => 'asc']],
        };
    }

    /**
     * @return list<string>
     */
    private static function facetNames(OptionFilter $filter): array
    {
        return array_values(array_unique(array_map(Facets::attribute(...), $filter->attributes)));
    }

    /**
     * The value's name under the first of the facets that has one; the value itself otherwise.
     *
     * @param array<string, string> $labels
     * @param list<string>          $facets
     */
    private static function label(array $labels, array $facets, string $value): string
    {
        foreach ($facets as $facet) {
            if (isset($labels[$facet . self::SEP . $value])) {
                return $labels[$facet . self::SEP . $value];
            }
        }

        return $value;
    }

    private static function decimal(float $number): string
    {
        $text = number_format($number, 4, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** A value deep in a decoded answer; null where the path does not lead. */
    private static function at(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }
}
