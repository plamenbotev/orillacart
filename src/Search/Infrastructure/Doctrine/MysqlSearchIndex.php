<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use OrillaCart\Search\Application\Dto\DocumentOption;
use OrillaCart\Search\Application\Dto\DocumentTerm;
use OrillaCart\Search\Application\Dto\SearchDocument;
use OrillaCart\Search\Application\SearchIndex;
use OrillaCart\Search\Domain\Facets;

/**
 * Keeps documents in the database engine's tables: one row per product, one per variant, and one per value a
 * product or variant can be filtered by. A product's rows are replaced together, in one transaction.
 */
final readonly class MysqlSearchIndex implements SearchIndex
{
    /** Enough for any product description; the rest only makes the index larger. */
    private const int MAX_TEXT_BYTES = 65000;
    private const int CHUNK = 200;

    public function __construct(private SearchTables $tables) {}

    public function put(SearchDocument $document): void
    {
        [$priceMin, $priceMax] = $document->priceRange();
        $this->tables->connection()->transactional(function (Connection $connection) use ($document, $priceMin, $priceMax): void {
            $this->delete($connection, [$document->productId]);
            $connection->insert($this->tables->documents(), [
                'product_id' => $document->productId,
                'title' => mb_substr($document->title, 0, 255),
                'text' => mb_strcut($document->text, 0, self::MAX_TEXT_BYTES),
                'price_min' => $priceMin,
                'price_max' => $priceMax,
                'in_stock' => (int) $document->inStock(),
                'featured' => (int) $document->featured,
                'on_sale' => (int) $document->onSale,
                'menu_order' => $document->menuOrder,
                'published_at' => $document->publishedAt->format('Y-m-d H:i:s'),
            ]);
            $variants = [];
            foreach ($document->variants as $variant) {
                $variants[] = [$variant->id, $document->productId, $variant->price, (int) $variant->inStock];
            }
            $this->insert($connection, $this->tables->variants(), ['variant_id', 'product_id', 'price', 'in_stock'], $variants);
            $this->insert($connection, $this->tables->values(), ['product_id', 'variant_id', 'facet', 'value', 'label'], self::values($document));
        });
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
     * @return list<list<string|int|null>> product_id, variant_id, facet, value, label
     */
    private static function values(SearchDocument $document): array
    {
        $rows = [];
        $add = static function (?int $variantId, string $facet, string $value, string $label) use (&$rows, $document): void {
            if ($value !== '') {
                $rows[$variantId . '|' . $facet . '|' . $value] = [$document->productId, $variantId, $facet, $value, mb_substr($label, 0, 255)];
            }
        };
        foreach ([Facets::CATEGORY => $document->categories, Facets::BRAND => $document->brands] as $facet => $terms) {
            foreach ($terms as $term) {
                \assert($term instanceof DocumentTerm);
                $add(null, $facet, (string) $term->id, $term->name);
            }
        }
        foreach ($document->skus as $sku) {
            $add(null, Facets::SKU, Facets::value($sku), $sku);
        }
        $option = static fn(?int $variantId, DocumentOption $option) => $add($variantId, Facets::attribute($option->attribute), Facets::value($option->name), $option->name);
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
}
