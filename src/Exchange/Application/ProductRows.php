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
use OrillaCart\Exchange\Application\Dto\Column;
use OrillaCart\Exchange\Application\Dto\ProductPostChange;
use OrillaCart\Exchange\Application\Dto\ProductRow;
use OrillaCart\Exchange\Domain\ExchangeRuleViolation;
use OrillaCart\Extension\Catalog\ImportsProductColumn;
use OrillaCart\Extension\Catalog\ProductColumn;
use OrillaCart\Inventory\Application\Dto\ChangeStockLevel;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\StockNote;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\StockReason;
use OrillaCart\Reports\Application\ShopCalendar;
use OrillaCart\Shared\Domain\TaxCode;

/**
 * Imports one row of a products file. The row finds its product by variation_id, id or SKU, in that order, and
 * changes only the columns the file has: an empty cell clears what may be empty (a SKU, a sale, a measure, a
 * stockroom's level) and keeps what may not (a price, a name). A row that finds nothing adds a product, which
 * needs a name and a price; variations are made on the product's screen, and a row only changes them. Add-ons'
 * importable columns save their value once the shop saved the row. Every cell is read before anything is written,
 * so a refused row changes nothing; a new product's post stays a draft until its details are saved, and goes
 * again when they are refused. Stock changes only in the stockrooms the file has a column of; the backorders an
 * item owes (a level below zero, as the export writes it) stay as they are unless the cell changes them, which
 * is refused. Dates without an offset are the shop's.
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
        private ShopCalendar $calendar,
    ) {}

    /**
     * @param array<string, string>                                     $row     the row's cells by column id, as the file has them
     * @param array<string, Column<ProductRow>|ProductColumn>|null $columns the file's columns, ProductColumns::all(), built once for many rows
     *
     * @return array{bool, ?string} whether it added a product, and what add-ons refused of a row saved otherwise
     *
     * @throws \OrillaCart\Shared\Domain\RuleViolation|\InvalidArgumentException why the row is refused
     */
    public function import(array $row, ?int $userId = null, ?array $columns = null): array
    {
        $columns ??= $this->columns->all();
        $row = array_map(self::cell(...), $row);
        $zone = $this->calendar->timezone();
        [$productId, $variationId] = $this->target($row);
        // Every cell is read first: a row refused for any of them changes nothing.
        $status = self::status($row);
        if ($productId === null) {
            $name = $row['name'] ?? '';
            if ($name === '' || ($row['price'] ?? '') === '') {
                throw new \InvalidArgumentException('A new product needs a name and a price.');
            }
            $product = null;
            $changes = self::productChanges($row, null, $zone);
            $levels = $this->levels(null, $row);
        } else {
            $product = $this->products->product($productId);
            if ($variationId === null) {
                $changes = self::productChanges($row, $product->sale, $zone);
                $levels = $this->levels(StockItemRef::product($productId), $row);
            } else {
                $variation = self::variation($product, $variationId);
                $changes = self::variationChanges($row, $variation, $zone);
                $levels = $this->levels(StockItemRef::variation($variationId), $row);
            }
        }
        $lists = self::lists($row);

        if ($product === null) {
            // A draft until its details are saved: a refused row leaves no product without them in the shop.
            $productId = $this->posts->create($row['name'] ?? '', 'draft');
            try {
                $saved = $this->products->save($productId, SaveProduct::unchanged($this->products->product($productId), $changes));
                $this->posts->change($productId, new ProductPostChange(null, $status ?? 'publish', $row['description'] ?? null, $row['short_description'] ?? null, ...$lists));
                $this->changeLevels(StockItemRef::product($productId), $levels, $userId);
            } catch (\Throwable $e) {
                $this->posts->delete($productId);

                throw $e;
            }
            return [true, $this->addOns($row, $saved, null, $columns)];
        }
        if ($variationId === null) {
            $this->products->save($productId, SaveProduct::unchanged($product, $changes));
            $post = $this->postChange($productId, $product, $row, $status, $lists);
            if (!$post->isEmpty()) {
                $this->posts->change($productId, $post);
            }
            $this->changeLevels(StockItemRef::product($productId), $levels, $userId);
        } else {
            $this->products->changeVariation($productId, $variationId, SaveVariation::unchanged(self::variation($product, $variationId), $changes));
            $this->changeLevels(StockItemRef::variation($variationId), $levels, $userId);
        }
        return [false, $this->addOns($row, $this->products->product($productId), $variationId, $columns)];
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
            $owner = $this->lookup->idsOfStockItem(StockItemRef::variation($variation))[0] ?? throw new ExchangeRuleViolation('There is no variation %d.', 'orillacart_unknown_variation', values: [$variation]);
            if ($product !== null && $product !== $owner) {
                throw new ExchangeRuleViolation('Variation %1$d is not one of product %2$d.', 'orillacart_unknown_variation', values: [$variation, $product]);
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
     * @return array{price?: string, type?: string, sale?: ?SaveSale, sku?: ?string, taxable?: bool, stockMode?: string, purchasable?: bool, featured?: bool, weight?: ?string, length?: ?string, width?: ?string, height?: ?string, backorders?: string, backorderLimit?: ?int, lowStockThreshold?: ?int, taxCode?: ?string}
     */
    private static function productChanges(array $row, ?SaleView $sale, \DateTimeZone $zone): array
    {
        $changes = self::common($row, $sale, $zone);
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
        // An empty cell takes the code off: the product goes by its tax group's again.
        if (\array_key_exists('tax_code', $row)) {
            try {
                $changes['taxCode'] = TaxCode::valid($row['tax_code']);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException('tax_code: ' . $e->getMessage(), 0, $e);
            }
        }

        return $changes;
    }

    /**
     * @param array<string, string> $row
     *
     * @return array{price?: string, sale?: ?SaveSale, sku?: ?string, stockMode?: string, weight?: ?string, length?: ?string, width?: ?string, height?: ?string, backorders?: ?string, backorderLimit?: ?int, lowStockThreshold?: ?int}
     */
    private static function variationChanges(array $row, VariationView $variation, \DateTimeZone $zone): array
    {
        $changes = self::common($row, $variation->sale, $zone);
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
    private static function common(array $row, ?SaleView $sale, \DateTimeZone $zone): array
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
            $from = \array_key_exists('sale_from', $row) ? self::moment($row['sale_from'], 'sale_from', $zone) : $sale?->from;
            $until = \array_key_exists('sale_until', $row) ? self::moment($row['sale_until'], 'sale_until', $zone) : $sale?->until;
            $changes['sale'] = $price === '' ? null : new SaveSale(self::amount($price, 'sale_price'), $from, $until);
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

    /**
     * The categories, brands and tags the row lists, each null when the file has no such column.
     *
     * @param array<string, string> $row
     *
     * @return array{categories: ?list<string>, brands: ?list<string>, tags: ?list<string>}
     */
    private static function lists(array $row): array
    {
        $list = static fn(string $column): ?array => \array_key_exists($column, $row)
            ? array_values(array_filter(array_map(trim(...), explode('|', $row[$column])), static fn(string $item): bool => $item !== ''))
            : null;

        return ['categories' => $list('categories'), 'brands' => $list('brands'), 'tags' => $list('tags')];
    }

    /**
     * What the row changes of an existing product's post: only what differs from it, so texts it leaves as they
     * are go through no filter again.
     *
     * @param array<string, string>                                                $row
     * @param array{categories: ?list<string>, brands: ?list<string>, tags: ?list<string>} $lists
     */
    private function postChange(int $productId, ProductView $product, array $row, ?string $status, array $lists): ProductPostChange
    {
        $post = $this->posts->of([$productId])[$productId] ?? null;
        $name = ($row['name'] ?? '') === '' || $row['name'] === $product->name ? null : $row['name'];
        if ($post === null) {
            return new ProductPostChange($name, $status, $row['description'] ?? null, $row['short_description'] ?? null, ...$lists);
        }
        $text = static fn(?string $value, string $was): ?string => $value === null || $value === trim($was) ? null : $value;

        return new ProductPostChange(
            $name,
            $status === $post->status ? null : $status,
            $text($row['description'] ?? null, $post->description),
            $text($row['short_description'] ?? null, $post->shortDescription),
            self::differing($lists['categories'], $post->categories),
            self::differing($lists['brands'], $post->brands),
            self::differing($lists['tags'], $post->tags),
        );
    }

    /**
     * The terms wanted, or null when they are the ones the post has, in whatever order.
     *
     * @param list<string>|null $wanted
     * @param list<string>      $was
     *
     * @return list<string>|null
     */
    private static function differing(?array $wanted, array $was): ?array
    {
        if ($wanted === null) {
            return null;
        }
        $sorted = $wanted;
        sort($sorted);
        sort($was);

        return $sorted === $was ? null : $wanted;
    }

    /**
     * The levels the row changes, read before anything is written: only the stockrooms it has a column of, and
     * of them those whose level the cell changes. An empty cell removes the stockroom's level. A level below zero
     * is what the item owes on backorders: as it is, it stays; changed, it is refused.
     *
     * @param StockItemRef|null    $item    null for a new product, which has no levels yet
     * @param array<string, string> $row
     *
     * @return array<int, array{?int, ?int}> by stockroom: the level wanted (null: none), and the one read
     */
    private function levels(?StockItemRef $item, array $row): array
    {
        $now = $item === null ? [] : ($this->stock->levelsOf([$item])["{$item->kind}:{$item->id}"] ?? []);
        $changes = [];
        foreach ($row as $column => $value) {
            if (!str_starts_with($column, ProductColumns::STOCK)) {
                continue;
            }
            $room = (int) substr($column, \strlen(ProductColumns::STOCK));
            $wanted = self::number($value, $column, \PHP_INT_MIN);
            $was = $now[$room] ?? null;
            if ($wanted === $was) {
                continue;
            }
            if ($wanted !== null && $wanted < 0) {
                throw new \InvalidArgumentException(\sprintf('%1$s: "%2$s" is below zero; a level below zero is the backorders the item owes, which an import leaves as they are (%3$s now).', $column, $value, $was === null ? 'none' : (string) $was));
            }
            $changes[$room] = [$wanted, $was];
        }

        return $changes;
    }

    /** @param array<int, array{?int, ?int}> $levels by stockroom: the level wanted, and the one read */
    private function changeLevels(StockItemRef $item, array $levels, ?int $userId): void
    {
        if ($levels === []) {
            return;
        }
        $this->stock->changeLevels(
            array_map(static fn(int $room, array $level): ChangeStockLevel => new ChangeStockLevel($item->kind, $item->id, $room, quantity: $level[0], was: $level[1]), array_keys($levels), array_values($levels)),
            new StockNote(StockReason::Import, null, $userId),
        );
    }

    /**
     * @param array<string, string>                                $row
     * @param array<string, Column<ProductRow>|ProductColumn> $columns
     *
     * @return string|null what add-ons refused; the row is saved all the same
     */
    private function addOns(array $row, ProductView $product, ?int $variationId, array $columns): ?string
    {
        $item = ProductsExport::item(new ProductRow($product, $variationId === null ? null : self::variation($product, $variationId), new Dto\ProductPost('', '', '', [], [], []), []));
        $refused = [];
        foreach ($columns as $id => $column) {
            if (!$column instanceof ImportsProductColumn || !\array_key_exists($id, $row)) {
                continue;
            }
            try {
                $column->import($item, $row[$id]);
            } catch (\InvalidArgumentException $e) {
                $refused[] = "{$column->label()}: {$e->getMessage()}";
            }
        }

        return $refused === [] ? null : 'Saved, but ' . implode('; ', $refused);
    }

    private static function variation(ProductView $product, int $variationId): VariationView
    {
        foreach ($product->variations as $variation) {
            if ($variation->id === $variationId) {
                return $variation;
            }
        }

        throw new ExchangeRuleViolation('There is no variation %d.', 'orillacart_unknown_variation', values: [$variationId]);
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
            throw new \InvalidArgumentException($least === \PHP_INT_MIN ? \sprintf('%s: "%s" is not a whole number.', $column, $value) : \sprintf('%s: "%s" is not a whole number of %d or more.', $column, $value, $least));
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

    /** A date and time; without an offset, in the shop's time zone. */
    private static function moment(string $value, string $column, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, $zone);
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
