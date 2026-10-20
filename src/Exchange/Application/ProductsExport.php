<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Catalog\Application\Dto\ProductView;
use OrillaCart\Catalog\Application\Dto\VariationView;
use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Exchange\Application\Dto\ChooseColumns;
use OrillaCart\Exchange\Application\Dto\Column;
use OrillaCart\Exchange\Application\Dto\ColumnView;
use OrillaCart\Exchange\Application\Dto\ProductPost;
use OrillaCart\Exchange\Application\Dto\ProductRow;
use OrillaCart\Exchange\Domain\ExchangeRuleViolation;
use OrillaCart\Exchange\Domain\ExportKind;
use OrillaCart\Extension\Catalog\ProductColumn;
use OrillaCart\Extension\Catalog\ProductItem;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;
use Psr\Log\LoggerInterface;

/**
 * Exports the catalog as the products file: a row for each product and one for each of its variations after it,
 * with the columns the shop manager chose, the shop's own and the add-ons'. The header names the columns by id,
 * as the import reads them back. A hundred products at a time, so a catalog of any size exports in little memory.
 */
final readonly class ProductsExport
{
    private const int BATCH = 100;

    public function __construct(
        private ProductColumns $columns,
        private ColumnChoice $choice,
        private ProductLookup $lookup,
        private ProductService $products,
        private ProductPosts $posts,
        private StockService $stock,
        private LoggerInterface $logger,
    ) {}

    /** @return list<ColumnView> the chosen ones in their order, then the others */
    public function columns(): array
    {
        return $this->choice->views(ExportKind::Products, $this->columns->all());
    }

    /**
     * @throws ExchangeRuleViolation for a column there is not
     *
     * @return list<ColumnView>
     */
    public function choose(ChooseColumns $command): array
    {
        $this->choice->choose(ExportKind::Products, $this->columns->all(), $command);

        return $this->columns();
    }

    public function export(ExportFile $file): void
    {
        $columns = $this->choice->chosen(ExportKind::Products, $this->columns->all());
        $file->start(array_map(ColumnChoice::id(...), $columns), array_map($this->choice->label(...), $columns));
        $after = 0;
        while (($ids = $this->lookup->ids($after, self::BATCH)) !== []) {
            $after = $ids[\count($ids) - 1];
            $posts = $this->posts->of($ids);
            foreach ($ids as $id) {
                $post = $posts[$id] ?? null;
                if ($post === null) {
                    continue;
                }
                try {
                    $product = $this->products->product($id);
                } catch (ProductNotFound) {
                    continue;
                }
                foreach ($this->rows($product, $post) as $row) {
                    $file->row(array_map(fn(Column|ProductColumn $column): string => $this->value($column, $row), $columns));
                }
            }
        }
        $file->finish();
    }

    /** @return list<ProductRow> the product, then its variations */
    private function rows(ProductView $product, ProductPost $post): array
    {
        $refs = [StockItemRef::product($product->id), ...array_map(static fn(VariationView $variation): StockItemRef => StockItemRef::variation($variation->id), $product->variations)];
        $levels = $this->stock->levelsOf($refs);
        $rows = [new ProductRow($product, null, $post, $levels["product:{$product->id}"] ?? [])];
        foreach ($product->variations as $variation) {
            $rows[] = new ProductRow($product, $variation, $post, $levels["variation:{$variation->id}"] ?? []);
        }

        return $rows;
    }

    /** @param Column<ProductRow>|ProductColumn $column */
    private function value(Column|ProductColumn $column, ProductRow $row): string
    {
        if ($column instanceof Column) {
            return ($column->value)($row);
        }
        try {
            return $column->value(self::item($row));
        } catch (\Throwable $e) {
            $this->logger->error('The product column {id} failed for product {product}: {message}', ['id' => $column->id(), 'product' => $row->product->id, 'message' => $e->getMessage(), 'exception' => $e]);

            return '';
        }
    }

    public static function item(ProductRow $row): ProductItem
    {
        return $row->variation === null
            ? new ProductItem($row->product->id, null, $row->product->name, $row->product->sku, $row->product->price)
            : new ProductItem($row->product->id, $row->variation->id, $row->product->name . ' – ' . $row->variation->label, $row->variation->sku, $row->variation->price);
    }
}
