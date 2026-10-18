<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\Doctrine;

use OrillaCart\Search\Application\CatalogSearch;
use OrillaCart\Search\Application\Dto\Facet;
use OrillaCart\Search\Application\Dto\FacetCount;
use OrillaCart\Search\Application\Dto\OptionFilter;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchResult;
use OrillaCart\Search\Application\Dto\SearchSort;
use OrillaCart\Search\Domain\Facets;

/**
 * Searches the database engine's tables. Product-wide conditions (words, categories, brands, featured, on sale)
 * test the document; options, price and stock must hold for one variant together. A facet counts the products
 * every other condition leaves, so choosing "M" still shows how many "L" would find.
 */
final readonly class MysqlCatalogSearch implements CatalogSearch
{
    /** The facet key of the price range, which no option filter can take. */
    private const string PRICE = "\0price";

    public function __construct(private SearchTables $tables) {}

    public function search(SearchQuery $query): SearchResult
    {
        $text = $query->text === null ? null : trim($query->text);
        $words = $text === null || $text === '' ? null : FullTextQuery::parse($text);

        $params = new SqlParams();
        $where = $this->where($query, $text, $words, $params, null, true);
        $documents = $this->tables->documents();
        $connection = $this->tables->connection();

        $total = (int) self::scalar($connection->fetchOne("SELECT COUNT(*) FROM {$documents} d WHERE {$where}", $params->values()));
        $order = $this->order($query->sort, $words, $params);
        $rows = $query->limit < 1 ? [] : $connection->fetchFirstColumn(
            \sprintf('SELECT d.product_id FROM %s d WHERE %s ORDER BY %s LIMIT %d OFFSET %d', $documents, $where, $order, $query->limit, max(0, $query->offset)),
            $params->values(),
        );
        $ids = array_map(static fn(mixed $id): int => (int) self::scalar($id), $rows);

        if (!$query->facets) {
            return new SearchResult($ids, $total);
        }

        $facets = [
            $this->termFacet(Facet::CATEGORIES, Facets::CATEGORY, $query, $text, $words),
            $this->termFacet(Facet::BRANDS, Facets::BRAND, $query, $text, $words),
        ];
        foreach ($query->options as $filter) {
            $facets[] = $this->optionFacet($filter, $query, $text, $words);
        }

        return new SearchResult($ids, $total, $facets, $this->priceRange($query, $text, $words));
    }

    private function termFacet(string $key, string $facet, SearchQuery $query, ?string $text, ?FullTextQuery $words): Facet
    {
        $params = new SqlParams();
        $where = $this->where($query, $text, $words, $params, $key, true);
        $sql = \sprintf(
            'SELECT f.value, MIN(f.label) AS label, COUNT(DISTINCT d.product_id) AS products FROM %s d JOIN %s f ON f.product_id = d.product_id AND f.variant_id IS NULL AND f.facet = %s WHERE %s GROUP BY f.value ORDER BY label',
            $this->tables->documents(),
            $this->tables->values(),
            $params->add($facet),
            $where,
        );

        return new Facet($key, $this->counts($sql, $params));
    }

    private function optionFacet(OptionFilter $filter, SearchQuery $query, ?string $text, ?FullTextQuery $words): Facet
    {
        $params = new SqlParams();
        $where = $this->where($query, $text, $words, $params, $filter->key, false);
        $variantWhere = $this->variantWhere($query, 'v', $params, $filter->key);
        $sql = \sprintf(
            'SELECT f.value, MIN(f.label) AS label, COUNT(DISTINCT d.product_id) AS products FROM %s d
             JOIN %s v ON v.product_id = d.product_id
             JOIN %s f ON f.product_id = d.product_id AND (f.variant_id = v.variant_id OR f.variant_id IS NULL) AND f.facet IN (%s)
             WHERE %s AND %s GROUP BY f.value ORDER BY label',
            $this->tables->documents(),
            $this->tables->variants(),
            $this->tables->values(),
            $params->list(self::attributeFacets($filter)),
            $where,
            $variantWhere === [] ? '1 = 1' : implode(' AND ', $variantWhere),
        );

        return new Facet($filter->key, $this->counts($sql, $params));
    }

    /**
     * @return array{string, string}|null
     */
    private function priceRange(SearchQuery $query, ?string $text, ?FullTextQuery $words): ?array
    {
        $params = new SqlParams();
        $where = $this->where($query, $text, $words, $params, self::PRICE, false);
        $variantWhere = [...$this->variantWhere($query, 'v', $params, self::PRICE), 'v.price IS NOT NULL'];
        $row = $this->tables->connection()->fetchAssociative(
            \sprintf('SELECT MIN(v.price) AS low, MAX(v.price) AS high FROM %s d JOIN %s v ON v.product_id = d.product_id WHERE %s AND %s', $this->tables->documents(), $this->tables->variants(), $where, implode(' AND ', $variantWhere)),
            $params->values(),
        );
        $low = $row['low'] ?? null;
        $high = $row['high'] ?? null;

        return \is_string($low) && \is_string($high) ? [self::decimal($low), self::decimal($high)] : null;
    }

    /**
     * @return list<FacetCount>
     */
    private function counts(string $sql, SqlParams $params): array
    {
        return array_map(
            static fn(array $row): FacetCount => new FacetCount((string) self::scalar($row['value'] ?? ''), (string) self::scalar($row['label'] ?? ''), (int) self::scalar($row['products'] ?? 0)),
            $this->tables->connection()->fetchAllAssociative($sql, $params->values()),
        );
    }

    /**
     * The conditions on the document d.
     *
     * @param string|null $except   leave out this facet's own condition, to count its values
     * @param bool        $variants include the variant conditions; facets over variants join them themselves
     */
    private function where(SearchQuery $query, ?string $text, ?FullTextQuery $words, SqlParams $params, ?string $except, bool $variants): string
    {
        $values = $this->tables->values();
        $conditions = [];
        if ($text !== null && $text !== '' && $words !== null) {
            $conditions[] = $this->textCondition($text, $words, $params);
        }
        if ($query->featuredOnly) {
            $conditions[] = 'd.featured = 1';
        }
        if ($query->onSaleOnly) {
            $conditions[] = 'd.on_sale = 1';
        }
        foreach ([Facet::CATEGORIES => [Facets::CATEGORY, $query->categoryIds], Facet::BRANDS => [Facets::BRAND, $query->brandIds]] as $key => [$facet, $ids]) {
            if ($ids !== [] && $except !== $key) {
                $conditions[] = \sprintf(
                    'EXISTS (SELECT 1 FROM %s t WHERE t.product_id = d.product_id AND t.variant_id IS NULL AND t.facet = %s AND t.value IN (%s))',
                    $values,
                    $params->add($facet),
                    $params->list(array_map(strval(...), $ids)),
                );
            }
        }
        if ($variants) {
            $variantWhere = $this->variantWhere($query, 'v', $params, $except);
            if ($variantWhere !== []) {
                $conditions[] = \sprintf('EXISTS (SELECT 1 FROM %s v WHERE v.product_id = d.product_id AND %s)', $this->tables->variants(), implode(' AND ', $variantWhere));
            }
        }

        return $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);
    }

    /**
     * The conditions one variant must meet together: stock, price, and an option of every chosen filter, its own
     * or the whole product's.
     *
     * @return list<string>
     */
    private function variantWhere(SearchQuery $query, string $variant, SqlParams $params, ?string $except): array
    {
        $conditions = [];
        if ($query->inStockOnly) {
            $conditions[] = "{$variant}.in_stock = 1";
        }
        if ($except !== self::PRICE) {
            if ($query->priceMin !== null) {
                $conditions[] = "{$variant}.price >= " . $params->add($query->priceMin);
            }
            if ($query->priceMax !== null) {
                $conditions[] = "{$variant}.price <= " . $params->add($query->priceMax);
            }
        }
        foreach ($query->options as $index => $filter) {
            if ($filter->values === [] || $filter->key === $except) {
                continue;
            }
            $alias = 'o' . $index;
            $conditions[] = \sprintf(
                'EXISTS (SELECT 1 FROM %1$s %2$s WHERE %2$s.product_id = %3$s.product_id AND (%2$s.variant_id = %3$s.variant_id OR %2$s.variant_id IS NULL) AND %2$s.facet IN (%4$s) AND %2$s.value IN (%5$s))',
                $this->tables->values(),
                $alias,
                $variant,
                $params->list(self::attributeFacets($filter)),
                $params->list(array_values(array_unique(array_map(Facets::value(...), $filter->values)))),
            );
        }

        return $conditions;
    }

    /**
     * The words, the title holding the short ones; or the start of a SKU.
     */
    private function textCondition(string $text, FullTextQuery $words, SqlParams $params): string
    {
        $parts = [];
        if ($words->boolean !== null) {
            $parts[] = 'MATCH (d.title, d.text) AGAINST (' . $params->add($words->boolean) . ' IN BOOLEAN MODE)';
        }
        foreach ($words->shortWords as $word) {
            $parts[] = 'd.title LIKE ' . $params->add('%' . addcslashes($word, '%_\\') . '%');
        }
        $sku = \sprintf(
            'EXISTS (SELECT 1 FROM %s s WHERE s.product_id = d.product_id AND s.facet = %s AND s.value LIKE %s)',
            $this->tables->values(),
            $params->add(Facets::SKU),
            $params->add(addcslashes(Facets::value($text), '%_\\') . '%'),
        );

        return $parts === [] ? $sku : '((' . implode(' AND ', $parts) . ") OR {$sku})";
    }

    private function order(SearchSort $sort, ?FullTextQuery $words, SqlParams $params): string
    {
        $shopOrder = 'd.menu_order ASC, d.title ASC, d.product_id ASC';

        return match ($sort) {
            SearchSort::Order => $shopOrder,
            // The title weighs more than the text.
            SearchSort::Relevance => $words?->boolean === null ? $shopOrder : \sprintf(
                'MATCH (d.title) AGAINST (%s IN BOOLEAN MODE) * 3 + MATCH (d.title, d.text) AGAINST (%s IN BOOLEAN MODE) DESC, %s',
                $params->add($words->boolean),
                $params->add($words->boolean),
                $shopOrder,
            ),
            SearchSort::Newest => 'd.published_at DESC, d.product_id DESC',
            SearchSort::Name => 'd.title ASC, d.product_id ASC',
            // Products whose price shoppers ask for come last either way.
            SearchSort::PriceAscending => 'd.price_min IS NULL, d.price_min ASC, d.title ASC, d.product_id ASC',
            SearchSort::PriceDescending => 'd.price_min IS NULL, d.price_min DESC, d.title ASC, d.product_id ASC',
        };
    }

    /**
     * @return list<string>
     */
    private static function attributeFacets(OptionFilter $filter): array
    {
        return array_values(array_unique(array_map(Facets::attribute(...), $filter->attributes)));
    }

    /** "12.5000" as "12.5". */
    private static function decimal(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    private static function scalar(mixed $value): string|int
    {
        return \is_int($value) || \is_string($value) ? $value : (is_numeric($value) ? (string) $value : '');
    }
}
