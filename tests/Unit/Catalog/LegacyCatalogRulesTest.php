<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\InvalidSelection;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Inventory\Domain\Stock;
use OrillaCart\Inventory\Domain\StockItem;
use OrillaCart\Inventory\Domain\StockItemKind;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tests\Fixtures\Catalog\LegacyCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The rebuilt catalog and stock rules against what the old plugin answered on the same data (legacy-catalog.json,
 * recorded by record-legacy-catalog.php from helpers/product_helper.php, front/models/product.php, helpers/cart.php
 * and helpers/order.php). Every case must match the old answer, except where the old code had one of these bugs;
 * those cases must match the old answer with that bug corrected.
 *
 * Prices and options
 *  - Sale without a price: a sale with an empty price made the product free while it ran. Now a sale needs a price.
 *  - Sale with only a start: a sale with no end date never applied. Now either end may be open.
 *  - Negative prices: options that take money off could push the price below zero. Now it stops at zero.
 *  - Options of other products: options that are not the product's were accepted and ignored. Now they are refused.
 *  - Inactive attribute sets: their options were not offered, but their required attributes still had to be
 *    chosen, so the product could not be bought; choosing their options anyway was accepted. Now an inactive set
 *    plays no part.
 *  - Variations: a product with variations offered the options of any product's variations, and a combination
 *    without a variation was sold at the product's price plus its options. Now it offers the options its own
 *    variations use, a combination must be one of them, and the variation's own price applies.
 *
 * Stock
 *  - Carts: items in any shopper's cart counted as sold, so abandoned carts made products look sold out. Now carts
 *    hold no stock; it is taken when an order is placed.
 *  - Inactive stockrooms: their stock counted as available, but was never taken for orders. Now it does not count.
 *  - Levels at or below zero: taking stock went through them, giving stock back or taking more than ordered.
 *    Now they are skipped; a level cannot go below zero.
 *  - Shortfall: an order for more than the stock took what there was and ignored the rest. Now nothing is taken
 *    and the order is refused.
 *
 * Choices the new rules refuse have no availability either ("invalid choices"); the old stock check did not look
 * at required options or variations.
 *
 * @phpstan-import-type Recorded from LegacyCatalog
 * @phpstan-import-type Fields from LegacyCatalog
 * @phpstan-import-type VariationFields from LegacyCatalog
 */
final class LegacyCatalogRulesTest extends TestCase
{
    private const string INVALID = 'invalid';

    /** @var Recorded */
    private array $recorded;

    /** @var array<string, int> */
    private array $corrections = [];

    protected function setUp(): void
    {
        $recorded = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/Fixtures/Catalog/legacy-catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($recorded);
        /** @var Recorded $recorded */
        $this->recorded = $recorded;
    }

    public function testTheOfferedOptionsMatchTheOldCodeExceptItsBugs(): void
    {
        $catalog = $this->catalog(true);
        foreach ($this->cases('offered') as $case) {
            /** @var array{product: string, options: list<list<string>>} $case */
            $product = $catalog->products[$case['product']];
            $expected = $case['options'];
            if ($product->hasVariations()) {
                $used = [];
                foreach ($product->variations() as $variation) {
                    foreach ($variation->options() as $option) {
                        $used[] = $catalog->optionKeys[$option->id()];
                    }
                }
                $expected = array_values(array_filter(array_map(
                    static fn(array $options): array => array_values(array_intersect($options, $used)),
                    $expected,
                ), static fn(array $options): bool => $options !== []));
                $this->tally($expected === $case['options'] ? 'unchanged' : 'variations');
            } else {
                $this->tally('unchanged');
            }

            $actual = array_map(
                static fn(array $offered): array => array_map(static fn(AttributeOption $option): string => $catalog->optionKeys[$option->id()], $offered[1]),
                $product->offeredOptions(),
            );
            self::assertSame($expected, $actual, $case['product']);
        }
        self::assertSame(1, $this->corrections['variations'] ?? 0, 'The jacket offered the hoodie\'s sizes.');
    }

    public function testPricesAndRequiredOptionsMatchTheOldCodeExceptItsBugs(): void
    {
        $catalog = $this->catalog(true);
        $required = [];
        foreach ($this->cases('required') as $case) {
            /** @var array{product: string, options: list<string>, accepted: bool} $case */
            $required[$case['product'] . json_encode($case['options'])] = $case['accepted'];
        }
        $prices = [];
        foreach ($this->cases('price') as $case) {
            /** @var array{product: string, options: list<string>, price: float|int, variation?: ?string} $case */
            $prices[$case['product'] . json_encode($case['options'])] = $case;
        }

        foreach ($prices as $key => $case) {
            [$expected, $correction] = $this->expectedPrice($catalog, $case, $required[$key] ?? true, $prices);
            $this->tally($correction);

            $message = \sprintf('%s, corrected for: %s', json_encode($case), $correction);
            self::assertSame($expected, $this->actualPrice($catalog, $case['product'], $case['options']), $message);
        }

        foreach (['sale without a price', 'sale with only a start', 'negative prices', 'options of other products', 'inactive attribute sets', 'variations'] as $name) {
            self::assertGreaterThan(0, $this->corrections[$name] ?? 0, $name);
        }
        self::assertGreaterThan(20, $this->corrections['unchanged'] ?? 0);
    }

    public function testAvailabilityMatchesTheOldCodeExceptItsBugs(): void
    {
        $byKey = [];
        foreach ($this->cases('availability') as $case) {
            /** @var array{check_stock: bool, carts: bool, product: string, options: list<string>, available: bool|int|string} $case */
            $byKey[json_encode([$case['check_stock'], $case['carts'], $case['product'], $case['options']])] = $case['available'];
        }

        foreach ([true, false] as $checkStock) {
            $catalog = $this->catalog($checkStock);
            foreach ($this->cases('availability') as $case) {
                /** @var array{check_stock: bool, carts: bool, product: string, options: list<string>, available: bool|int|string} $case */
                if ($case['check_stock'] !== $checkStock) {
                    continue;
                }
                $old = $case['available'];
                $withoutCarts = $byKey[json_encode([$checkStock, false, $case['product'], $case['options']])];
                [$expected, $correction] = $this->expectedAvailability($catalog, $case['product'], $case['options'], $withoutCarts);
                if ($case['carts'] && $old !== $withoutCarts && $correction === 'unchanged') {
                    $correction = 'carts';
                }
                $this->tally($correction);

                $message = \sprintf('%s, corrected for: %s', json_encode($case), $correction);
                self::assertSame($expected, $this->actualAvailability($catalog, $case['product'], $case['options']), $message);
            }
        }

        foreach (['carts', 'inactive stockrooms', 'variations', 'inactive attribute sets'] as $name) {
            self::assertGreaterThan(0, $this->corrections[$name] ?? 0, $name);
        }
        self::assertGreaterThan(100, $this->corrections['unchanged'] ?? 0);
    }

    public function testTakingStockMatchesTheOldCodeExceptItsBugs(): void
    {
        $catalog = $this->catalog(true);
        foreach ($this->cases('deduction') as $case) {
            /** @var array{kind: string, item: string, quantity: int, taken: array<string, int>} $case */
            $item = $catalog->stockItem($case['kind'], $case['item']);
            $old = $case['taken'];
            $stock = new Stock($item, $catalog->levels($item));
            $available = $stock->available();

            if ($available !== null && $available < $case['quantity']) {
                $expected = self::INVALID;
                $correction = 'shortfall';
            } else {
                // Without the levels at or below zero, and no more than the quantity ordered.
                $expected = [];
                $left = $case['quantity'];
                foreach ($old as $stockroom => $amount) {
                    if ($amount > 0 && $left > 0) {
                        $expected[$stockroom] = min($amount, $left);
                        $left -= $expected[$stockroom];
                    }
                }
                $correction = $expected === $old ? 'unchanged' : 'levels at or below zero';
            }
            $this->tally($correction);

            try {
                $actual = [];
                foreach ($stock->take($case['quantity']) as $stockroomId => $amount) {
                    $actual[$catalog->stockroomKeys[$stockroomId]] = $amount;
                }
            } catch (InsufficientStock) {
                $actual = self::INVALID;
            }
            self::assertSame($expected, $actual, \sprintf('%s, corrected for: %s', json_encode($case), $correction));
        }

        foreach (['unchanged', 'shortfall', 'levels at or below zero'] as $name) {
            self::assertGreaterThan(0, $this->corrections[$name] ?? 0, $name);
        }
    }

    /**
     * The old price, or "invalid" where the old cart refused the choice, with the one correction it needs.
     *
     * @param array{product: string, options: list<string>, price: float|int, variation?: ?string} $case
     * @param array<string, array{product: string, options: list<string>, price: float|int, variation?: ?string}> $prices
     *
     * @return array{string, string}
     */
    private function expectedPrice(LegacyCatalog $catalog, array $case, bool $accepted, array $prices): array
    {
        $key = $case['product'];
        if ($catalog->isVariation($key)) {
            return $this->correctedPrice($this->fields($catalog, $key), $case['price']);
        }
        $fields = $this->fields($catalog, $key);
        $owners = [$key, ...$fields['sets'] ?? []];
        $activeOwners = [$key, ...array_filter($fields['sets'] ?? [], fn(string $set): bool => $this->sets()[$set][0] === 'yes')];

        foreach ($case['options'] as $option) {
            $owner = explode('.', $option)[0];
            if (!\in_array($owner, $owners, true)) {
                return [self::INVALID, $accepted ? 'options of other products' : 'unchanged'];
            }
            if (!\in_array($owner, $activeOwners, true)) {
                return [self::INVALID, $accepted ? 'inactive attribute sets' : 'unchanged'];
            }
        }

        if (isset($fields['variations'])) {
            $variation = $case['variation'] ?? null;
            if ($variation === null) {
                return [self::INVALID, $accepted ? 'variations' : 'unchanged'];
            }
            [$price] = $this->correctedPrice($this->fields($catalog, $variation), $prices[$variation . '[]']['price']);

            return [$price, $price === self::money($case['price']) ? 'unchanged' : 'variations'];
        }

        foreach ($activeOwners as $owner) {
            foreach ($owner === $key ? $fields['own'] ?? [] : $this->sets()[$owner][1] as $attributeKey => $attribute) {
                $chosen = array_filter($case['options'], static fn(string $option): bool => str_starts_with($option, "{$owner}.{$attributeKey}."));
                if ($attribute[1] === 'yes' && $chosen === []) {
                    return [self::INVALID, $accepted ? 'required options' : 'unchanged'];
                }
            }
        }
        [$price, $correction] = $this->correctedPrice($fields, $case['price']);
        if (!$accepted) {
            // The old cart asked for a required option of an inactive set, which the product page did not offer.
            return [$price, 'inactive attribute sets'];
        }

        return [$price, $correction];
    }

    /**
     * @param array{price: string, sale?: array{string, string}} $fields
     *
     * @return array{string, string}
     */
    private function correctedPrice(array $fields, float|int $old): array
    {
        $price = Money::of(self::decimal($old));
        $correction = 'unchanged';
        if (isset($fields['sale']) && $fields['sale'][0] === '' && $fields['sale'][1] === 'active') {
            $price = $price->add(Money::of($fields['price']));
            $correction = 'sale without a price';
        }
        if (isset($fields['sale']) && $fields['sale'][1] === 'start-only') {
            $price = $price->add(Money::of($fields['sale'][0]))->subtract(Money::of($fields['price']));
            $correction = 'sale with only a start';
        }
        if ($price->isNegative()) {
            $price = Money::zero();
            $correction = $correction === 'unchanged' ? 'negative prices' : $correction . ' + negative prices';
        }

        return [(string) $price, $correction];
    }

    /**
     * @param list<string> $options
     */
    private function actualPrice(LegacyCatalog $catalog, string $key, array $options): string
    {
        if ($catalog->isVariation($key)) {
            $product = $catalog->products[$catalog->variationParents[$key]];

            return (string) $product->variation($catalog->variationIds[$key])->pricing()->priceAt($catalog->now);
        }
        try {
            return (string) $catalog->products[$key]->configure(array_map(static fn(string $option): int => $catalog->optionIds[$option], $options), $catalog->now)->unitPrice;
        } catch (InvalidSelection) {
            return self::INVALID;
        }
    }

    /**
     * How many the new rules allow, worked out from the old answer without carts: null for no limit, "invalid" for
     * a choice the new rules refuse.
     *
     * @param list<string> $options
     *
     * @return array{int|string|null, string}
     */
    private function expectedAvailability(LegacyCatalog $catalog, string $key, array $options, bool|int|string $old): array
    {
        $old = match (true) {
            $old === true => null,
            $old === false => 0,
            default => $old,
        };
        if (!$catalog->isVariation($key)) {
            try {
                $configuration = $catalog->products[$key]->configure(array_map(static fn(string $option): int => $catalog->optionIds[$option], $options), $catalog->now);
            } catch (InvalidSelection) {
                return [self::INVALID, $old === 'error' ? 'inactive attribute sets' : 'invalid choices'];
            }
            if ($configuration->variation !== null) {
                $variationKey = array_search($configuration->variation->id(), $catalog->variationIds, true);
                self::assertIsString($variationKey);
                [$expected] = $this->expectedAvailability($catalog, $variationKey, [], $this->recordedAvailability($variationKey));

                return [$expected, $expected === $old ? 'unchanged' : 'variations'];
            }
            $mode = $configuration->stockMode();
            $items = [$catalog->stockItem('product', $key), ...array_map(static fn(string $option): StockItem => $catalog->stockItem('option', $option), $options)];
        } else {
            $product = $catalog->products[$catalog->variationParents[$key]];
            $mode = $product->variation($catalog->variationIds[$key])->stockMode();
            $items = [$catalog->stockItem('product', $key)];
        }

        if ($mode !== StockMode::Tracked) {
            return [$mode === StockMode::Unavailable ? 0 : null, 'unchanged'];
        }
        // The old rule counted every stockroom; the corrected one only active stockrooms.
        $expected = null;
        $counted = null;
        foreach ($items as $item) {
            $levels = $catalog->levels($item);
            if ($levels === []) {
                continue;
            }
            $active = array_sum(array_map(static fn($level): int => $level->stockroom()->isActive() ? $level->quantity() : 0, $levels));
            $all = array_sum(array_map(static fn($level): int => $level->quantity(), $levels));
            $expected = $expected === null ? $active : min($expected, $active);
            $counted = $counted === null ? $all : min($counted, $all);
        }
        self::assertSame($counted, $old, 'The old rule without carts, worked out again.');

        return [$expected, $expected === $old ? 'unchanged' : 'inactive stockrooms'];
    }

    /**
     * @param list<string> $options
     */
    private function actualAvailability(LegacyCatalog $catalog, string $key, array $options): int|string|null
    {
        if ($catalog->isVariation($key)) {
            $product = $catalog->products[$catalog->variationParents[$key]];
            $variation = $product->variation($catalog->variationIds[$key]);
            $mode = $variation->stockMode();
            $items = [$catalog->stockItem('product', $key)];
        } else {
            try {
                $configuration = $catalog->products[$key]->configure(array_map(static fn(string $option): int => $catalog->optionIds[$option], $options), $catalog->now);
            } catch (InvalidSelection) {
                return self::INVALID;
            }
            $mode = $configuration->stockMode();
            $items = $configuration->variation !== null
                ? [new StockItem(StockItemKind::Variation, $configuration->variation->id())]
                : [$catalog->stockItem('product', $key), ...array_map(static fn(AttributeOption $option): StockItem => new StockItem(StockItemKind::Option, $option->id()), $configuration->options)];
        }

        return match ($mode) {
            StockMode::AlwaysAvailable => null,
            StockMode::Unavailable => 0,
            StockMode::Tracked => array_reduce(
                $items,
                static function (?int $carry, StockItem $item) use ($catalog): ?int {
                    $available = new Stock($item, $catalog->levels($item))->available();

                    return $available === null ? $carry : ($carry === null ? $available : min($carry, $available));
                },
            ),
        };
    }

    private function recordedAvailability(string $key): bool|int|string
    {
        foreach ($this->cases('availability') as $case) {
            if ($case['check_stock'] === true && $case['carts'] === false && $case['product'] === $key && $case['options'] === []) {
                /** @var bool|int|string */
                return $case['available'];
            }
        }
        self::fail("No recorded availability for {$key}.");
    }

    private function catalog(bool $checkStock): LegacyCatalog
    {
        return new LegacyCatalog($this->recorded, new \DateTimeImmutable('2026-10-17 12:00:00 UTC'), $checkStock);
    }

    /**
     * @return Fields|VariationFields
     */
    private function fields(LegacyCatalog $catalog, string $key): array
    {
        if ($catalog->isVariation($key)) {
            $variations = $catalog->recorded['products'][$catalog->variationParents[$key]]['variations'] ?? [];

            return $variations[$key];
        }

        return $catalog->recorded['products'][$key];
    }

    /**
     * @return array<string, array{string, array<string, array{string, string, int, array<string, array{string, string, float|int}>}>}>
     */
    private function sets(): array
    {
        return $this->recorded['sets'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cases(string $kind): array
    {
        /** @var array<string, list<array<string, mixed>>> $cases */
        $cases = $this->recorded['cases'];

        return $cases[$kind];
    }

    /**
     * @param string $correction one name, or several joined by " + "
     */
    private function tally(string $correction): void
    {
        foreach (explode(' + ', $correction) as $name) {
            $this->corrections[$name] = ($this->corrections[$name] ?? 0) + 1;
        }
    }

    private static function money(float|int $value): string
    {
        return (string) Money::of(self::decimal($value));
    }

    private static function decimal(float|int $value): string
    {
        return rtrim(rtrim(\sprintf('%.4F', $value), '0'), '.');
    }
}
