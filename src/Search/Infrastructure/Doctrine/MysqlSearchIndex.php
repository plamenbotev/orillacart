<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use OrillaCart\Search\Application\Dto\DocumentOption;
use OrillaCart\Search\Application\Dto\DocumentTerm;
use OrillaCart\Search\Application\Dto\DocumentText;
use OrillaCart\Search\Application\Dto\SearchDocument;
use OrillaCart\Search\Application\SearchIndex;
use OrillaCart\Search\Domain\Facets;

/**
 * Keeps documents in the database engine's tables: one row per product and language it has words in, one per
 * variant, and one per value a product or variant can be filtered by, with its labels in the site's languages. A
 * product's rows are replaced together, in one transaction.
 */
final readonly class MysqlSearchIndex implements SearchIndex
{
    /** Enough for any product description; the rest only makes the index larger. */
    private const int MAX_TEXT_BYTES = 65000;
    private const int CHUNK = 200;

    public function __construct(private SearchTables $tables) {}

    public function put(array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $products = [];
        $variants = [];
        $values = [];
        foreach ($documents as $document) {
            [$priceMin, $priceMax] = $document->priceRange();
            // The translations share what they are filtered and sorted by, besides their words.
            foreach ([new DocumentText($document->locale ?? '', $document->title, $document->text), ...$document->translations] as $index => $words) {
                $products[] = [
                    $document->productId,
                    $words->locale,
                    (int) ($index === 0),
                    mb_substr($words->title, 0, 255),
                    mb_strcut($words->text, 0, self::MAX_TEXT_BYTES),
                    $priceMin,
                    $priceMax,
                    (int) $document->inStock(),
                    (int) $document->featured,
                    (int) $document->onSale,
                    $document->menuOrder,
                    $document->publishedAt->format('Y-m-d H:i:s'),
                ];
            }
            foreach ($document->variants as $variant) {
                $variants[] = [$variant->id, $document->productId, $variant->price, (int) $variant->inStock];
            }
            array_push($values, ...self::values($document));
        }
        // The batch's rows go in a few statements, in one transaction.
        $this->tables->connection()->transactional(function (Connection $connection) use ($documents, $products, $variants, $values): void {
            $this->delete($connection, array_map(static fn(SearchDocument $document): int => $document->productId, $documents));
            $this->insert($connection, $this->tables->documents(), ['product_id', 'locale', 'original', 'title', 'text', 'price_min', 'price_max', 'in_stock', 'featured', 'on_sale', 'menu_order', 'published_at'], $products);
            $this->insert($connection, $this->tables->variants(), ['variant_id', 'product_id', 'price', 'in_stock'], $variants);
            $this->insert($connection, $this->tables->values(), ['product_id', 'variant_id', 'facet', 'value', 'label', 'labels'], $values);
        });
    }

    public function productIds(): array
    {
        return array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $this->tables->connection()->fetchFirstColumn("SELECT product_id FROM {$this->tables->documents()} WHERE original = 1"));
    }

    public function remove(array $productIds): void
    {
        if ($productIds !== []) {
            $this->tables->connection()->transactional(fn(Connection $connection) => $this->delete($connection, $productIds));
        }
    }

    /**
     * @param list<int> $productIds
     */
    private function delete(Connection $connection, array $productIds): void
    {
        $in = implode(', ', array_fill(0, \count($productIds), '?'));
        foreach ([$this->tables->values(), $this->tables->variants(), $this->tables->documents()] as $table) {
            $connection->executeStatement("DELETE FROM {$table} WHERE product_id IN ({$in})", $productIds);
        }
    }

    /**
     * @param list<string>                         $columns
     * @param list<list<string|int|null>>          $rows
     */
    private function insert(Connection $connection, string $table, array $columns, array $rows): void
    {
        $row = '(' . implode(', ', array_fill(0, \count($columns), '?')) . ')';
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $connection->executeStatement(
                \sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, \count($chunk), $row))),
                array_merge(...$chunk),
            );
        }
    }

    /**
     * Each value once per product or variant.
     *
     * @return list<list<string|int|null>> product_id, variant_id, facet, value, label, labels
     */
    private static function values(SearchDocument $document): array
    {
        $rows = [];
        $add = static function (?int $variantId, string $facet, string $value, string $label, ?string $labels = null) use (&$rows, $document): void {
            if ($value !== '') {
                $rows[$variantId . '|' . $facet . '|' . $value] = [$document->productId, $variantId, $facet, $value, mb_substr($label, 0, 255), $labels];
            }
        };
        foreach ([Facets::CATEGORY => $document->categories, Facets::BRAND => $document->brands, Facets::TAG => $document->tags] as $facet => $terms) {
            foreach ($terms as $term) {
                \assert($term instanceof DocumentTerm);
                $add(null, $facet, (string) $term->id, $term->name, self::labels($term->labels));
            }
        }
        foreach ($document->skus as $sku) {
            $add(null, Facets::SKU, Facets::value($sku), $sku);
        }
        $option = static fn(?int $variantId, DocumentOption $option) => $add($variantId, Facets::attribute($option->attribute), Facets::value($option->name), $option->name, self::labels($option->labels));
        foreach ($document->options as $documentOption) {
            $option(null, $documentOption);
        }
        foreach ($document->variants as $variant) {
            foreach ($variant->options as $documentOption) {
                $option($variant->id, $documentOption);
            }
        }

        return array_values($rows);
    }

    /**
     * @param array<string, string> $labels by locale
     *
     * @return string|null as the labels column keeps them
     */
    private static function labels(array $labels): ?string
    {
        if ($labels === []) {
            return null;
        }
        $json = json_encode(array_map(static fn(string $label): string => mb_substr($label, 0, 255), $labels), JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    }
}
