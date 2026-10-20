<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Catalog\Application\Dto\AttributeView;
use OrillaCart\Catalog\Application\Dto\OptionView;
use OrillaCart\Catalog\Application\Dto\SaleView;
use OrillaCart\Exchange\Application\Dto\Column;
use OrillaCart\Exchange\Application\Dto\ProductRow;
use OrillaCart\Extension\Catalog\ProductColumn;
use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;

/**
 * The columns of the products file: the shop's own, a stock column for each stockroom (stock_ID), then the
 * add-ons' (ProductColumn). The file names them by id, as the import reads them. A product's row leaves its
 * variations' columns empty and the other way round; a variation's empty value follows its product.
 */
final readonly class ProductColumns
{
    public const string LIST = ' | ';
    public const string STOCK = 'stock_';

    public function __construct(
        private InstalledExtensions $extensions,
        private StockroomService $stockrooms,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<Column<ProductRow>>
     */
    public function own(): array
    {
        $bool = static fn(bool $value): string => $value ? 'yes' : 'no';
        $sale = static fn(ProductRow $row): ?SaleView => $row->variation === null ? $row->product->sale : $row->variation->sale;
        $columns = [
            new Column('id', 'Product ID', static fn(ProductRow $row): string => (string) $row->product->id),
            new Column('variation_id', 'Variation ID', static fn(ProductRow $row): string => $row->variation === null ? '' : (string) $row->variation->id),
            new Column('name', 'Name', static fn(ProductRow $row): string => $row->variation === null ? $row->product->name : $row->product->name . ' – ' . $row->variation->label, true),
            new Column('type', 'Type', self::productOnly(static fn(ProductRow $row): string => $row->product->type), true),
            new Column('status', 'Status', self::productOnly(static fn(ProductRow $row): string => $row->post->status), true),
            new Column('sku', 'SKU', self::either(static fn(ProductRow $row): string => $row->product->sku ?? '', static fn(ProductRow $row): string => $row->variation->sku ?? ''), true),
            new Column('price', 'Price', self::either(static fn(ProductRow $row): string => $row->product->price, static fn(ProductRow $row): string => $row->variation->price ?? ''), true),
            new Column('sale_price', 'Sale price', static fn(ProductRow $row): string => $sale($row)->price ?? '', true),
            new Column('sale_from', 'Sale from', static fn(ProductRow $row): string => $sale($row)?->from?->format(\DATE_ATOM) ?? '', true),
            new Column('sale_until', 'Sale until', static fn(ProductRow $row): string => $sale($row)?->until?->format(\DATE_ATOM) ?? '', true),
            new Column('stock_mode', 'Stock', self::either(static fn(ProductRow $row): string => $row->product->stockMode, static fn(ProductRow $row): string => $row->variation->stockMode ?? ''), true),
            new Column('backorders', 'Backorders', self::either(static fn(ProductRow $row): string => $row->product->backorders, static fn(ProductRow $row): string => $row->variation->backorders ?? ''), true),
            new Column('backorder_limit', 'Owe at most', self::either(static fn(ProductRow $row): string => self::number($row->product->backorderLimit), static fn(ProductRow $row): string => self::number($row->variation?->backorderLimit)), true),
            new Column('low_stock_threshold', 'Low stock at', self::either(static fn(ProductRow $row): string => self::number($row->product->lowStockThreshold), static fn(ProductRow $row): string => self::number($row->variation?->lowStockThreshold)), true),
            new Column('weight', 'Weight', self::either(static fn(ProductRow $row): string => $row->product->weight ?? '', static fn(ProductRow $row): string => $row->variation->weight ?? ''), true),
            new Column('length', 'Length', self::either(static fn(ProductRow $row): string => $row->product->length ?? '', static fn(ProductRow $row): string => $row->variation->length ?? ''), true),
            new Column('width', 'Width', self::either(static fn(ProductRow $row): string => $row->product->width ?? '', static fn(ProductRow $row): string => $row->variation->width ?? ''), true),
            new Column('height', 'Height', self::either(static fn(ProductRow $row): string => $row->product->height ?? '', static fn(ProductRow $row): string => $row->variation->height ?? ''), true),
            new Column('taxable', 'Taxable', self::productOnly(static fn(ProductRow $row): string => $bool($row->product->taxable)), true),
            new Column('purchasable', 'Purchasable', self::productOnly(static fn(ProductRow $row): string => $bool($row->product->purchasable)), true),
            new Column('featured', 'Featured', self::productOnly(static fn(ProductRow $row): string => $bool($row->product->featured)), true),
            new Column('categories', 'Categories', self::productOnly(static fn(ProductRow $row): string => implode(self::LIST, $row->post->categories)), true),
            new Column('brands', 'Brands', self::productOnly(static fn(ProductRow $row): string => implode(self::LIST, $row->post->brands)), true),
            new Column('tags', 'Tags', self::productOnly(static fn(ProductRow $row): string => implode(self::LIST, $row->post->tags)), true),
            new Column('short_description', 'Short description', self::productOnly(static fn(ProductRow $row): string => $row->post->shortDescription), true),
            new Column('description', 'Description', self::productOnly(static fn(ProductRow $row): string => $row->post->description), true),
            new Column('options', 'Options', self::either(
                static fn(ProductRow $row): string => implode(self::LIST, array_map(static fn(AttributeView $attribute): string => $attribute->name . ': ' . implode(', ', array_map(static fn(OptionView $option): string => $option->name, $attribute->options)), $row->product->attributes)),
                static fn(ProductRow $row): string => $row->variation->label ?? '',
            )),
        ];
        foreach ($this->stockrooms->stockrooms() as $room) {
            $id = $room->id;
            $columns[] = new Column(self::STOCK . $id, 'Stock: ' . $room->name, static fn(ProductRow $row): string => isset($row->levels[$id]) ? (string) $row->levels[$id] : '', true);
        }

        return $columns;
    }

    /**
     * The shop's columns and the add-ons', by id.
     *
     * @return array<string, Column<ProductRow>|ProductColumn>
     */
    public function all(): array
    {
        $columns = [];
        foreach ($this->own() as $column) {
            $columns[$column->id] = $column;
        }
        foreach ($this->extensions->productColumns() as $column) {
            if (isset($columns[$column->id()]) || str_starts_with($column->id(), self::STOCK)) {
                $this->logger->error('An add-on\'s product column uses the id "{id}", which is the shop\'s own. It is left out.', ['id' => $column->id()]);
                continue;
            }
            $columns[$column->id()] = $column;
        }

        return $columns;
    }

    /**
     * A product's value; empty on its variations' rows.
     *
     * @param \Closure(ProductRow): string $value
     *
     * @return \Closure(ProductRow): string
     */
    private static function productOnly(\Closure $value): \Closure
    {
        return static fn(ProductRow $row): string => $row->variation === null ? $value($row) : '';
    }

    /**
     * @param \Closure(ProductRow): string $product   on a product's row
     * @param \Closure(ProductRow): string $variation on a variation's
     *
     * @return \Closure(ProductRow): string
     */
    private static function either(\Closure $product, \Closure $variation): \Closure
    {
        return static fn(ProductRow $row): string => $row->variation === null ? $product($row) : $variation($row);
    }

    private static function number(?int $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
