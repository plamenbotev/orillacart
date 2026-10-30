<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\BulkEdit;
use OrillaCart\Catalog\Application\Dto\ProductView;
use OrillaCart\Catalog\Application\Dto\SaleView;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveSale;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\Dto\VariationView;
use OrillaCart\Catalog\Domain\Bundle;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Inventory\Application\Dto\ChangeStockLevel;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\StockNote;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\InventoryRuleViolation;
use OrillaCart\Inventory\Domain\StockReason;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\Persistence\Transactions;

/**
 * Changes many products at once: their prices (set, or raised and lowered by an amount or a percentage, in the
 * shop's decimals), their sales (set, a percentage off the price, or removed) and their stock in one stockroom
 * (set, or added to). A product's variations change with it, each from its own price and stock. A product that
 * cannot take the change is left as it was and named in the answer; the others change.
 */
final readonly class BulkEdits
{
    public function __construct(
        private ProductService $products,
        private StockService $stock,
        private SettingsService $settings,
        private Transactions $transactions,
        private ProductTranslations $translations,
    ) {}

    /**
     * @param list<int> $ids products
     *
     * @return list<Text> why products were left as they were: "Lamp: …"
     *
     * @throws CatalogRuleViolation for a change that is not one
     */
    public function apply(array $ids, BulkEdit $edit, ?int $userId = null): array
    {
        self::check($edit);
        $refused = [];
        // A product and its translation chosen together are edited once: they are one product (design 51).
        foreach (array_values(array_unique($this->translations->originals($ids))) as $id) {
            $name = "#{$id}";
            try {
                $product = $this->products->product($id);
                $name = $product->name;
                if (!$product->saved) {
                    continue;
                }
                $levels = $this->levels($product, $edit);
                // All of it or none: a variation that cannot take its price leaves the product's own unchanged too.
                $this->transactions->run(function () use ($product, $edit, $levels, $userId): void {
                    $this->prices($product, $edit);
                    if ($levels === []) {
                        return;
                    }
                    try {
                        $this->stock->changeLevels($levels, new StockNote(StockReason::BulkEdit, null, $userId), overwrite: true);
                    } catch (InventoryRuleViolation $e) {
                        throw $e->errorCode === 'orillacart_invalid_quantity' ? new CatalogRuleViolation('the stock would be below zero.', $e->errorCode, $e) : $e;
                    }
                });
            } catch (RuleViolation $e) {
                $refused[] = Text::of('%1$s: %2$s', $name, $e->text);
            }
        }

        return $refused;
    }

    private function prices(ProductView $product, BulkEdit $edit): void
    {
        if ($edit->price === null && $edit->sale === null) {
            return;
        }
        // Every price is worked out first: one that cannot be leaves the product, variations and all, as it was.
        // The product's own price is left alone where it is not what it sells for, as quick edit leaves it: its
        // variations have theirs, or a bundle adds up its items'.
        $ownPrice = $product->variations === [] && $product->bundle?->pricing !== Bundle::SUM;
        [$price, $sale] = $ownPrice ? $this->priced($product->price, $product->sale, $edit) : [null, null];
        $variations = [];
        foreach ($product->variations as $variation) {
            $variations[] = [$variation, ...$this->priced($variation->price, $variation->sale, $edit)];
        }
        if ($price !== null) {
            $this->products->save($product->id, SaveProduct::unchanged($product, ['price' => $price, 'sale' => $sale]));
        }
        foreach ($variations as [$variation, $variationPrice, $variationSale]) {
            $this->products->changeVariation($product->id, $variation->id, SaveVariation::unchanged($variation, ['price' => $variationPrice, 'sale' => $variationSale]));
        }
    }

    /**
     * @return array{string, ?SaveSale}
     */
    private function priced(string $current, ?SaleView $sale, BulkEdit $edit): array
    {
        $decimals = $this->settings->current()->priceDecimals;
        $price = Money::of($current);
        $by = $edit->priceBy === '' ? Money::zero() : Money::of($edit->priceBy);
        $price = match ($edit->price) {
            'set' => $by,
            'raise' => $price->add($by),
            'lower' => $price->subtract($by),
            'raise_percent' => $price->add($price->percent($edit->priceBy)),
            'lower_percent' => $price->subtract($price->percent($edit->priceBy)),
            default => $price,
        };
        $price = $price->rounded($decimals);
        if ($price->isNegative()) {
            throw new CatalogRuleViolation('the price would be below zero.', 'orillacart_invalid_price');
        }
        $kept = $sale === null ? null : new SaveSale($sale->price, $sale->from, $sale->until);
        $newSale = match ($edit->sale) {
            'set' => new SaveSale(Money::of($edit->saleBy)->rounded($decimals)->toFixed(), $sale?->from, $sale?->until),
            'percent_off' => new SaveSale($price->subtract($price->percent($edit->saleBy))->rounded($decimals)->toFixed(), $sale?->from, $sale?->until),
            'remove' => null,
            default => $kept,
        };

        return [$price->toFixed(), $newSale];
    }

    /**
     * The change to each of the product's items in the stockroom: "set" sets the level whatever it is now, "add"
     * adds to the level as it is when it is saved, under its lock, so units sold meanwhile stay sold; adding to an
     * item owed on backorder lowers what it owes.
     *
     * @return list<ChangeStockLevel>
     *
     * @throws CatalogRuleViolation for stock that would be set below zero
     */
    private function levels(ProductView $product, BulkEdit $edit): array
    {
        if ($edit->stock === null || $edit->stockroomId === null) {
            return [];
        }
        if ($edit->stock === 'set' && $edit->stockBy < 0) {
            throw new CatalogRuleViolation('the stock would be below zero.', 'orillacart_invalid_quantity');
        }
        $items = $product->variations === []
            ? [StockItemRef::product($product->id)]
            : array_map(static fn(VariationView $variation): StockItemRef => StockItemRef::variation($variation->id), $product->variations);
        if ($edit->stock === 'add' && $edit->stockBy < 0) {
            // Refused before anything is saved, the price too; checked again under the levels' lock.
            foreach ($this->stock->levelsOf($items) as $levels) {
                if (($levels[$edit->stockroomId] ?? 0) + $edit->stockBy < 0) {
                    throw new CatalogRuleViolation('the stock would be below zero.', 'orillacart_invalid_quantity');
                }
            }
        }

        // That stockroom's level only: the others, and what they owe, stay as they are.
        return array_map(static fn(StockItemRef $item): ChangeStockLevel => $edit->stock === 'set'
            ? new ChangeStockLevel($item->kind, $item->id, $edit->stockroomId, $edit->stockBy)
            : new ChangeStockLevel($item->kind, $item->id, $edit->stockroomId, change: $edit->stockBy), $items);
    }

    /**
     * @throws CatalogRuleViolation
     */
    private static function check(BulkEdit $edit): void
    {
        $amount = static fn(string $value): bool => preg_match(SaveProduct::AMOUNT, $value) === 1;
        if ($edit->price !== null && (!\in_array($edit->price, BulkEdit::PRICES, true) || !$amount($edit->priceBy))) {
            throw new CatalogRuleViolation('Give the price change an amount, such as 19.90 or 10 for 10 %.', 'orillacart_invalid_bulk_edit');
        }
        if ($edit->sale !== null && (!\in_array($edit->sale, BulkEdit::SALES, true) || ($edit->sale !== 'remove' && !$amount($edit->saleBy)))) {
            throw new CatalogRuleViolation('Give the sale an amount, such as 15, or 20 for a sale of 20%.', 'orillacart_invalid_bulk_edit');
        }
        if (\in_array($edit->price, ['raise_percent', 'lower_percent'], true) && Money::of($edit->priceBy)->compare(Money::of('1000')) > 0 || $edit->sale === 'percent_off' && Money::of($edit->saleBy)->compare(Money::of('100')) > 0) {
            throw new CatalogRuleViolation('A percentage off is at most 100.', 'orillacart_invalid_bulk_edit');
        }
        if ($edit->stock !== null && (!\in_array($edit->stock, BulkEdit::STOCKS, true) || $edit->stockroomId === null)) {
            throw new CatalogRuleViolation('Choose the stockroom whose stock changes.', 'orillacart_invalid_bulk_edit');
        }
    }
}
