<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Catalog;

use OrillaCart\Catalog\Domain\Attribute;
use OrillaCart\Catalog\Domain\AttributeDraft;
use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\OptionDraft;
use OrillaCart\Catalog\Domain\Pricing;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Catalog\Domain\ProductDetails;
use OrillaCart\Catalog\Domain\ProductType;
use OrillaCart\Catalog\Domain\Sale;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Catalog\Domain\VariationDetails;
use OrillaCart\Inventory\Domain\StockItem;
use OrillaCart\Inventory\Domain\StockItemKind;
use OrillaCart\Inventory\Domain\StockLevel;
use OrillaCart\Inventory\Domain\Stockroom;
use OrillaCart\Inventory\Domain\StockroomDetails;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tests\Fixtures\Inventory\InMemoryStockroomRepository;

/**
 * The data of legacy-catalog.json rebuilt in the new model, with the keys the recording uses mapped to ids.
 *
 * Mapping: an option's "+" or "-" price becomes a positive or negative adjustment; "*" and "/" become none, as the
 * old price ignored them. A sale with an empty price is no sale. A stock level below zero becomes zero, the lowest
 * the new model holds. The old global "check stock" setting and the per-product "manage stock" and "stock" fields
 * become each product's stock mode.
 *
 * @phpstan-type VariationFields array{price: string, sale?: array{string, string}, manage_stock?: string, stock?: string, options: list<string>}
 * @phpstan-type Fields array{price: string, sale?: array{string, string}, manage_stock?: string, stock?: string, sets?: list<string>, own?: array<string, array{string, string, int, array<string, array{string, string, float|int}>}>, variations?: array<string, VariationFields>}
 * @phpstan-type Recorded array{stockrooms: array<string, array{string, string, string, int}>, sets: array<string, array{string, array<string, array{string, string, int, array<string, array{string, string, float|int}>}>}>, products: array<string, Fields>, levels: list<array{string, string, string, int}>, carts: list<array{string, string, int}>, cases: array<string, list<array<string, mixed>>>}
 */
final class LegacyCatalog
{
    /** @var array<string, int> */
    public array $optionIds = [];
    /** @var array<int, string> */
    public array $optionKeys = [];
    /** @var array<string, Product> */
    public array $products = [];
    /** @var array<string, int> variation key => id */
    public array $variationIds = [];
    /** @var array<string, string> variation key => product key */
    public array $variationParents = [];
    /** @var array<string, Stockroom> */
    public array $stockrooms = [];
    /** @var array<int, string> */
    public array $stockroomKeys = [];
    /** @var array<string, AttributeSet> */
    private array $sets = [];

    /**
     * @param Recorded $recorded
     */
    public function __construct(public readonly array $recorded, public readonly \DateTimeImmutable $now, bool $checkStock)
    {
        $ids = new CatalogIds();
        $products = new InMemoryProductRepository($ids);
        $sets = new InMemoryAttributeSetRepository($ids);
        $stockrooms = new InMemoryStockroomRepository();

        foreach ($recorded['stockrooms'] as $key => [$name, $published, $unit, $max]) {
            $days = $unit === 'week' ? $max * 7 : $max;
            $this->stockrooms[$key] = new Stockroom(new StockroomDetails($name, 0, $days, $published === 'yes'));
            $stockrooms->save($this->stockrooms[$key]);
            $this->stockroomKeys[$this->stockrooms[$key]->id()] = $key;
        }

        foreach ($recorded['sets'] as $key => [$published, $attributes]) {
            $set = new AttributeSet(ucfirst($key), $published === 'yes');
            $set->replaceAttributes(self::drafts($attributes));
            $sets->save($set);
            $this->sets[$key] = $set;
            $this->mapOptions($key, $attributes, $set->ownAttributes());
        }

        $productId = 100;
        $variationId = 5000;
        foreach ($recorded['products'] as $key => $fields) {
            $product = new Product(++$productId, new ProductDetails(ProductType::Physical, $this->pricing($fields), stockMode: self::stockMode($fields, $checkStock)));
            $product->replaceAttributes(self::drafts($fields['own'] ?? []), array_map(fn(string $set): AttributeSet => $this->sets[$set], $fields['sets'] ?? []));
            $products->save($product);
            $this->mapOptions($key, $fields['own'] ?? [], $product->ownAttributes());

            foreach ($fields['variations'] ?? [] as $variationKey => $variation) {
                $created = $product->addVariation(++$variationId, new VariationDetails(
                    array_map(fn(string $option): int => $this->optionIds[$option], $variation['options']),
                    $this->pricing($variation),
                    stockMode: self::stockMode($variation, $checkStock),
                ));
                $products->save($product);
                $this->variationIds[$variationKey] = $created->id();
                $this->variationParents[$variationKey] = $key;
            }
            $this->products[$key] = $product;
        }
    }

    public function isVariation(string $key): bool
    {
        return isset($this->variationIds[$key]);
    }

    /**
     * The stock item a recorded key names: products and variations were both product posts in the old plugin.
     */
    public function stockItem(string $kind, string $key): StockItem
    {
        return match (true) {
            $kind === 'option' => new StockItem(StockItemKind::Option, $this->optionIds[$key]),
            $this->isVariation($key) => new StockItem(StockItemKind::Variation, $this->variationIds[$key]),
            default => new StockItem(StockItemKind::Product, $this->products[$key]->id()),
        };
    }

    /**
     * Fresh stock levels of the item, as recorded.
     *
     * @return list<StockLevel>
     */
    public function levels(StockItem $item): array
    {
        $levels = [];
        foreach ($this->recorded['levels'] as [$kind, $key, $stockroom, $quantity]) {
            if ($this->stockItem($kind, $key)->equals($item)) {
                $levels[] = new StockLevel($this->stockrooms[$stockroom], $item, max(0, $quantity));
            }
        }

        return $levels;
    }

    /**
     * @param array<string, array{string, string, int, array<string, array{string, string, float|int}>}> $attributes
     *
     * @return list<AttributeDraft>
     */
    private static function drafts(array $attributes): array
    {
        $drafts = [];
        foreach ($attributes as [$name, $required, $ordering, $options]) {
            $drafts[] = new AttributeDraft(null, $name, array_values(array_map(
                static fn(array $option): OptionDraft => new OptionDraft(null, $option[0], match ($option[1]) {
                    '+' => Money::of(self::decimal($option[2])),
                    '-' => Money::zero()->subtract(Money::of(self::decimal($option[2]))),
                    default => Money::zero(),
                }),
                $options,
            )), $required === 'yes');
        }

        return $drafts;
    }

    /**
     * @param array<string, array{string, string, int, array<string, array{string, string, float|int}>}> $attributes
     * @param list<Attribute>                                                                                    $created
     */
    private function mapOptions(string $prefix, array $attributes, array $created): void
    {
        foreach (array_keys($attributes) as $index => $attributeKey) {
            foreach (array_keys($attributes[$attributeKey][3]) as $position => $optionKey) {
                $id = $created[$index]->options()[$position]->id();
                $this->optionIds["{$prefix}.{$attributeKey}.{$optionKey}"] = $id;
                $this->optionKeys[$id] = "{$prefix}.{$attributeKey}.{$optionKey}";
            }
        }
    }

    /**
     * @param array{price: string, sale?: array{string, string}} $fields
     */
    private function pricing(array $fields): Pricing
    {
        $sale = null;
        if (isset($fields['sale']) && $fields['sale'][0] !== '') {
            [$from, $until] = match ($fields['sale'][1]) {
                'active' => ['-1 day', '+1 day'],
                'upcoming' => ['+1 day', '+2 days'],
                'ended' => ['-2 days', '-1 day'],
                'start-only' => ['-1 day', null],
                'end-only' => [null, '+1 day'],
                default => throw new \LogicException('Unknown sale window ' . $fields['sale'][1]),
            };
            $sale = new Sale(Money::of($fields['sale'][0]), $from === null ? null : $this->now->modify($from), $until === null ? null : $this->now->modify($until));
        }

        return new Pricing(Money::of($fields['price']), $sale);
    }

    /**
     * @param array{manage_stock?: string, stock?: string} $fields
     */
    private static function stockMode(array $fields, bool $checkStock): StockMode
    {
        $manage = $fields['manage_stock'] ?? 'global';
        $stock = $fields['stock'] ?? 'global';

        return match (true) {
            $manage === 'no', $manage === 'global' && !$checkStock => StockMode::AlwaysAvailable,
            $stock === 'no' => StockMode::Unavailable,
            $stock === 'yes' => StockMode::AlwaysAvailable,
            default => StockMode::Tracked,
        };
    }

    private static function decimal(float|int $value): string
    {
        return rtrim(rtrim(\sprintf('%.4F', $value), '0'), '.');
    }
}
