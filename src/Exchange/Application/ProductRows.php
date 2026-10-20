<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Catalog\Application\Dto\ProductView;
use OrillaCart\Catalog\Application\Dto\SaleView;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveSale;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\Dto\VariationView;
use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Catalog\Domain\Backorders;
use OrillaCart\Exchange\Application\Dto\ProductPostChange;
use OrillaCart\Exchange\Application\Dto\ProductRow;
use OrillaCart\Exchange\Domain\ExchangeRuleViolation;
use OrillaCart\Extension\Catalog\ImportsProductColumn;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\StockNote;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\StockReason;

/**
 * Imports one row of a products file. The row finds its product by variation_id, id or SKU, in that order, and
 * changes only the columns the file has: an empty cell clears what may be empty (a SKU, a sale, a measure, a
 * stockroom's level) and keeps what may not (a price, a name). A row that finds nothing adds a product, which
 * needs a name and a price; variations are made on the product's screen, and a row only changes them. Add-ons'
 * importable columns save their value once the shop saved the row.
 */
final readonly class ProductRows
{
    public const array STATUSES = ['publish', 'draft', 'pending', 'private'];

    public function __construct(
        private ProductService $products,
        private ProductLookup $lookup,
        private ProductPosts $posts,
        private StockService $stock,
        private ProductColumns $columns,
    ) {}

    /**
     * @param array<string, string> $row the row's cells by column id, as the file has them
     *
     * @return bool whether it added a product
     *
     * @throws \OrillaCart\Shared\Domain\RuleViolation|\InvalidArgumentException why the row is refused
     */
    public function import(array $row, ?int $userId = null): bool
    {
        $row = array_map(self::cell(...), $row);
        [$productId, $variationId] = $this->target($row);
        $created = false;
        if ($productId === null) {
            $name = $row['name'] ?? '';
            if ($name === '' || ($row['price'] ?? '') === '') {
                throw new \InvalidArgumentException('A new product needs a name and a price.');
            }
            $productId = $this->posts->create($name, self::status($row) ?? 'publish');
            $created = true;
        }
        $product = $this->products->product($productId);
        if ($variationId === null) {
            $product = $this->products->save($productId, SaveProduct::unchanged($product, self::productChanges($row, $product)));
            $post = self::postChange($row, $created);
            if (!$post->isEmpty()) {
                $this->posts->change($productId, $post);
            }
            $this->levels(StockItemRef::product($productId), $row, $userId);
        } else {
            $variation = self::variation($product, $variationId);
            $this->products->changeVariation($productId, $variationId, SaveVariation::unchanged($variation, self::variationChanges($row, $variation)));
            $this->levels(StockItemRef::variation($variationId), $row, $userId);
        }
        $this->addOns($row, $this->products->product($productId), $variationId);

        return $created;
    }

    /**
     * @param array<string, string> $row
     *
     * @return array{?int, ?int} the product's id, and the variation's for a variation; nulls for a new product
     */
    private function target(array $row): array
    {
        $variation = self::id($row, 'variation_id');
        $product = self::id($row, 'id');
        if ($variation !== null) {
            $owner = $this->lookup->idsOfStockItem(StockItemRef::variation($variation))[0] ?? throw new ExchangeRuleViolation(\sprintf('There is no variation %d.', $variation), 'orillacart_unknown_variation');
            if ($product !== null && $product !== $owner) {
                throw new ExchangeRuleViolation(\sprintf('Variation %d is not one of product %d.', $variation, $product), 'orillacart_unknown_variation');
            }

            return [$owner, $variation];
        }
        if ($product !== null) {
            return [$product, null];
        }
        $sku = $row['sku'] ?? '';

        return $sku === '' ? [null, null] : ($this->lookup->withSku($sku) ?? [null, null]);
    }

    /**
     * @param array<string, string> $row
     *
     * @return array{price?: string, type?: string, sale?: ?SaveSale, sku?: ?string, taxable?: bool, stockMode?: string, purchasable?: bool, featured?: bool, weight?: ?string, length?: ?string, width?: ?string, height?: ?string, backorders?: string, backorderLimit?: ?int, lowStockThreshold?: ?int}
     */
    private static function productChanges(array $row, ProductView $product): array
    {
        $changes = self::common($row, $product->sale);
        if (($row['type'] ?? '') !== '') {
            $changes['type'] = self::choice($row['type'], SaveProduct::types(), 'type');
        }
        foreach (['taxable' => 'taxable', 'purchasable' => 'purchasable', 'featured' => 'featured'] as $column => $field) {
            if (($row[$column] ?? '') !== '') {
                $changes[$field] = self::yes($row[$column], $column);
            }
        }
        if (($row['backorders'] ?? '') !== '') {
            $changes['backorders'] = self::choice($row['backorders'], Backorders::values(), 'backorders');
        }

        return $changes;
    }

    /**
     * @param array<string, string> $row
     *
     * @return array{price?: string, sale?: ?SaveSale, sku?: ?string, stockMode?: string, weight?: ?string, length?: ?string, width?: ?string, height?: ?string, backorders?: ?string, backorderLimit?: ?int, lowStockThreshold?: ?int}
     */
    private static function variationChanges(array $row, VariationView $variation): array
    {
        $changes = self::common($row, $variation->sale);
        if (\array_key_exists('backorders', $row)) {
            // Empty: as its product.
            $changes['backorders'] = $row['backorders'] === '' ? null : self::choice($row['backorders'], Backorders::values(), 'backorders');
        }

        return $changes;
    }

    /**
     * What products and variations both have.
     *
     * @param array<string, string> $row
     *
     * @return array{price?: string, sale?: ?SaveSale, sku?: ?string, stockMode?: string, weight?: ?string, length?: ?string, width?: ?string, height?: ?string, backorderLimit?: ?int, lowStockThreshold?: ?int}
     */
    private static function common(array $row, ?SaleView $sale): array
    {
        $changes = [];
        if (($row['price'] ?? '') !== '') {
            $changes['price'] = self::amount($row['price'], 'price');
        }
        if (\array_key_exists('sku', $row)) {
            $changes['sku'] = $row['sku'] === '' ? null : $row['sku'];
        }
        if (\array_key_exists('sale_price', $row) || \array_key_exists('sale_from', $row) || \array_key_exists('sale_until', $row)) {
            $price = \array_key_exists('sale_price', $row) ? $row['sale_price'] : ($sale->price ?? '');
            $changes['sale'] = $price === '' ? null : new SaveSale(
                self::amount($price, 'sale_price'),
                \array_key_exists('sale_from', $row) ? self::moment($row['sale_from'], 'sale_from') : $sale?->from,
                \array_key_exists('sale_until', $row) ? self::moment($row['sale_until'], 'sale_until') : $sale?->until,
            );
        }
        if (($row['stock_mode'] ?? '') !== '') {
            $changes['stockMode'] = self::choice($row['stock_mode'], SaveProduct::stockModes(), 'stock_mode');
        }
        foreach (['weight' => 'weight', 'length' => 'length', 'width' => 'width', 'height' => 'height'] as $column => $field) {
            if (\array_key_exists($column, $row)) {
                $changes[$field] = $row[$column] === '' ? null : self::measure($row[$column], $column);
            }
        }
        if (\array_key_exists('backorder_limit', $row)) {
            $changes['backorderLimit'] = self::number($row['backorder_limit'], 'backorder_limit', 1);
        }
        if (\array_key_exists('low_stock_threshold', $row)) {
            $changes['lowStockThreshold'] = self::number($row['low_stock_threshold'], 'low_stock_threshold', 0);
        }

        return $changes;
    }

    /** @param array<string, string> $row */
    private static function postChange(array $row, bool $created): ProductPostChange
    {
        $list = static fn(string $column): ?array => \array_key_exists($column, $row)
            ? array_values(array_filter(array_map(trim(...), explode('|', $row[$column])), static fn(string $item): bool => $item !== ''))
            : null;

        return new ProductPostChange(
            $created || ($row['name'] ?? '') === '' ? null : $row['name'],
            $created ? null : self::status($row),
            $row['description'] ?? null,
            $row['short_description'] ?? null,
            $list('categories'),
            $list('brands'),
            $list('tags'),
        );
    }

    /**
     * Sets the levels of the stockrooms the row has a column of; the others stay. An empty cell removes the
     * stockroom's level.
     *
     * @param array<string, string> $row
     */
    private function levels(StockItemRef $item, array $row, ?int $userId): void
    {
        $changed = false;
        $levels = $this->stock->levelsOf([$item])["{$item->kind}:{$item->id}"] ?? [];
        foreach ($row as $column => $value) {
            if (!str_starts_with($column, ProductColumns::STOCK)) {
                continue;
            }
            $room = (int) substr($column, \strlen(ProductColumns::STOCK));
            $changed = true;
            if ($value === '') {
                unset($levels[$room]);
            } else {
                $levels[$room] = self::number($value, $column, 0) ?? 0;
            }
        }
        if ($changed) {
            $this->stock->setLevels($item, new SetStockLevels(array_map(static fn(int $room, int $quantity): SetStockLevel => new SetStockLevel($room, $quantity), array_keys($levels), array_values($levels))), new StockNote(StockReason::Import, null, $userId));
        }
    }

    /**
     * @param array<string, string> $row
     *
     * @throws \InvalidArgumentException when an add-on refused its value; the row is saved
     */
    private function addOns(array $row, ProductView $product, ?int $variationId): void
    {
        $item = ProductsExport::item(new ProductRow($product, $variationId === null ? null : self::variation($product, $variationId), new Dto\ProductPost('', '', '', [], [], []), []));
        $refused = [];
        foreach ($this->columns->all() as $id => $column) {
            if (!$column instanceof ImportsProductColumn || !\array_key_exists($id, $row)) {
                continue;
            }
            try {
                $column->import($item, $row[$id]);
            } catch (\InvalidArgumentException $e) {
                $refused[] = "{$column->label()}: {$e->getMessage()}";
            }
        }
        if ($refused !== []) {
            throw new \InvalidArgumentException('Saved, but ' . implode('; ', $refused));
        }
    }

    private static function variation(ProductView $product, int $variationId): VariationView
    {
        foreach ($product->variations as $variation) {
            if ($variation->id === $variationId) {
                return $variation;
            }
        }

        throw new ExchangeRuleViolation(\sprintf('There is no variation %d.', $variationId), 'orillacart_unknown_variation');
    }

    /**
     * What the file has in the cell: without the apostrophe the export puts before what looks like a formula.
     */
    private static function cell(string $value): string
    {
        $value = trim($value);

        return str_starts_with($value, "'") && preg_match(CsvFile::FORMULA, substr($value, 1)) === 1 ? substr($value, 1) : $value;
    }

    /** @param array<string, string> $row */
    private static function status(array $row): ?string
    {
        return ($row['status'] ?? '') === '' ? null : self::choice($row['status'], self::STATUSES, 'status');
    }

    /** @param array<string, string> $row */
    private static function id(array $row, string $column): ?int
    {
        $value = $row[$column] ?? '';
        if ($value === '') {
            return null;
        }
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException(\sprintf('%s: "%s" is not an id.', $column, $value));
        }

        return (int) $value;
    }

    private static function amount(string $value, string $column): string
    {
        $value = str_replace(',', '.', $value);
        if (preg_match(SaveProduct::AMOUNT, $value) !== 1) {
            throw new \InvalidArgumentException(\sprintf('%s: "%s" is not an amount such as 19.90.', $column, $value));
        }

        return $value;
    }

    private static function measure(string $value, string $column): string
    {
        $value = str_replace(',', '.', $value);
        if (preg_match(SaveProduct::MEASURE, $value) !== 1) {
            throw new \InvalidArgumentException(\sprintf('%s: "%s" is not a number of zero or more.', $column, $value));
        }

        return $value;
    }

    private static function number(string $value, string $column, int $least): ?int
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^-?\d{1,9}$/', $value) !== 1 || (int) $value < $least) {
            throw new \InvalidArgumentException(\sprintf('%s: "%s" is not a whole number of %d or more.', $column, $value, $least));
        }

        return (int) $value;
    }

    private static function yes(string $value, string $column): bool
    {
        return match (strtolower($value)) {
            'yes', 'y', '1', 'true' => true,
            'no', 'n', '0', 'false' => false,
            default => throw new \InvalidArgumentException(\sprintf('%s: "%s" is not yes or no.', $column, $value)),
        };
    }

    private static function moment(string $value, string $column): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException(\sprintf('%s: "%s" is not a date.', $column, $value));
        }
    }

    /**
     * @param list<string> $choices
     */
    private static function choice(string $value, array $choices, string $column): string
    {
        $value = strtolower($value);
        if (!\in_array($value, $choices, true)) {
            throw new \InvalidArgumentException(\sprintf('%s: "%s" is not one of %s.', $column, $value, implode(', ', $choices)));
        }

        return $value;
    }
}
