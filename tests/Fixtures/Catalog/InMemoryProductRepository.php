<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Catalog;

use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\Bundle;
use OrillaCart\Catalog\Domain\MarkedProduct;
use OrillaCart\Catalog\Domain\Pricing;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Catalog\Domain\ProductCriteria;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\ProductType;
use OrillaCart\Catalog\Domain\Variation;

final class InMemoryProductRepository implements ProductRepository
{
    /** @var array<int, Product> */
    private array $products = [];
    /** @var array<int, array<string, true>> add-ons' marks, by product */
    private array $marks = [];

    public function __construct(private readonly CatalogIds $ids = new CatalogIds()) {}

    public function countTaxedBy(int $taxGroupId): int
    {
        return \count(array_filter($this->products, static fn(Product $product): bool => $product->details()->taxGroupId === $taxGroupId));
    }

    public function find(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function attributeIdsAmong(array $ids): array
    {
        $known = [];
        foreach ($this->products as $product) {
            foreach ($product->ownAttributes() as $attribute) {
                $known[] = $attribute->id();
            }
        }

        return array_values(array_intersect($ids, $known));
    }

    public function optionIdsAmong(array $ids): array
    {
        return array_map(static fn(AttributeOption $option): int => $option->id(), $this->optionsWithIds($ids));
    }

    public function existing(array $ids): array
    {
        return array_values(array_filter($ids, fn(int $id): bool => isset($this->products[$id])));
    }

    public function idsWithSku(string $text): array
    {
        $ids = [];
        foreach ($this->products as $product) {
            $skus = [$product->details()->sku, ...array_map(static fn($variation): ?string => $variation->sku(), $product->variations())];
            foreach ($skus as $sku) {
                if ($sku !== null && str_contains($sku, $text)) {
                    $ids[] = $product->id();
                    break;
                }
            }
        }

        return $ids;
    }

    public function options(?string $text, int $offset, int $limit): array
    {
        $options = [];
        foreach ($this->products as $product) {
            foreach ($product->attributes() as $attribute) {
                foreach ($attribute->options() as $option) {
                    $names = [$option->name(), $attribute->name(), $attribute->set()?->name() ?? ''];
                    if ($text === null || array_filter($names, static fn(string $name): bool => str_contains($name, $text)) !== []) {
                        $options[$option->id()] = $option;
                    }
                }
            }
        }

        return [\array_slice(array_values($options), $offset, $limit), \count($options)];
    }

    public function ids(int $afterId, int $limit): array
    {
        $ids = array_filter(array_keys($this->products), static fn(int $id): bool => $id > $afterId);
        sort($ids);

        return \array_slice($ids, 0, $limit);
    }

    public function idsOfferingOption(int $optionId): array
    {
        $ids = [];
        foreach ($this->products as $product) {
            foreach ($product->attributes() as $attribute) {
                foreach ($attribute->options() as $option) {
                    if ($option->isSaved() && $option->id() === $optionId) {
                        $ids[] = $product->id();
                        continue 3;
                    }
                }
            }
        }

        return $ids;
    }

    public function attributeNames(): array
    {
        $names = [];
        foreach ($this->products as $product) {
            foreach ($product->attributes() as $attribute) {
                $names[$attribute->name()] = true;
            }
        }
        $names = array_map(strval(...), array_keys($names));
        sort($names);

        return $names;
    }

    public function bundlesHolding(array $productIds): array
    {
        $ids = [];
        foreach ($this->products as $product) {
            foreach ($product->bundle()->items ?? [] as $item) {
                if (\in_array($item->productId, $productIds, true)) {
                    $ids[] = $product->id();
                    break;
                }
            }
        }
        sort($ids);

        return $ids;
    }

    public function idsUsingSet(int $setId): array
    {
        $ids = [];
        foreach ($this->products as $product) {
            foreach ($product->sets() as $set) {
                if ($set->id() === $setId) {
                    $ids[] = $product->id();
                }
            }
        }

        return $ids;
    }

    public function findByVariation(int $variationId): ?Product
    {
        foreach ($this->products as $product) {
            foreach ($product->variations() as $variation) {
                if ($variation->id() === $variationId) {
                    return $product;
                }
            }
        }

        return null;
    }

    public function optionsWithIds(array $ids): array
    {
        return array_values(array_filter($this->options(null, 0, PHP_INT_MAX)[0], static fn(AttributeOption $option): bool => \in_array($option->id(), $ids, true)));
    }

    public function idsMatching(ProductCriteria $criteria): array
    {
        $ids = [];
        foreach ($this->products as $product) {
            if (self::meets($product, $criteria) && ($criteria->addOnMark === null || $this->isMarked($product->id(), $criteria->addOnMark))) {
                $ids[] = $product->id();
            }
        }
        sort($ids);

        return $ids;
    }

    public function mark(int $productId, string $mark): bool
    {
        new MarkedProduct($productId, $mark);
        if (!isset($this->products[$productId])) {
            return false;
        }
        $this->marks[$productId][$mark] = true;

        return true;
    }

    public function unmark(int $productId, string $mark): void
    {
        unset($this->marks[$productId][$mark]);
    }

    public function isMarked(int $productId, string $mark): bool
    {
        return isset($this->marks[$productId][$mark]);
    }

    public function stockedItems(): array
    {
        $items = [];
        $products = $this->products;
        ksort($products);
        foreach ($products as $product) {
            if ($product->isBundle()) {
                continue;
            }
            if (!$product->hasVariations()) {
                $items[] = ['product' => $product->id(), 'variation' => null, 'mode' => $product->details()->stockMode];
            }
            foreach ($product->variations() as $variation) {
                $items[] = ['product' => $product->id(), 'variation' => $variation->id(), 'mode' => $variation->sellingMode()];
            }
        }

        return $items;
    }

    public function bundles(): array
    {
        $bundles = [];
        $products = $this->products;
        ksort($products);
        foreach ($products as $product) {
            $bundle = $product->bundle();
            if ($bundle !== null) {
                $bundles[] = ['product' => $product->id(), 'mode' => $product->details()->stockMode, 'bundle' => $bundle];
            }
        }

        return $bundles;
    }

    private static function meets(Product $product, ProductCriteria $criteria): bool
    {
        $details = $product->details();
        $variations = $product->variations();
        $sellable = $variations === [] ? [$details->pricing] : array_map(static fn(Variation $variation): Pricing => $variation->pricing(), $variations);
        $any = static fn(\Closure $test): bool => array_filter($sellable, $test) !== [];
        $onSale = static fn(Pricing $pricing): bool => $pricing->sale()?->isActiveAt($criteria->now) ?? false;

        return match ($criteria->kind) {
            ProductCriteria::SHIPS => $details->type === ProductType::Physical,
            ProductCriteria::DOES_NOT_SHIP => $details->type === ProductType::Virtual,
            ProductCriteria::WITH_VARIATIONS => $variations !== [],
            ProductCriteria::BUNDLE => $product->isBundle(),
            ProductCriteria::WITH_DOWNLOADS => $product->downloads() !== [],
            default => true,
        } && match ($criteria->shown) {
            ProductCriteria::FEATURED => $details->featured,
            ProductCriteria::NOT_PURCHASABLE => !$details->purchasable,
            ProductCriteria::PRICE_HIDDEN => $details->priceVisibility->isHidden($criteria->shopHidesPrices),
            ProductCriteria::ON_SALE => $onSale($details->pricing) || array_filter($variations, static fn(Variation $variation): bool => $onSale($variation->pricing())) !== [],
            default => true,
        } && ($criteria->minPrice === null || $any(static fn(Pricing $pricing): bool => $pricing->priceAt($criteria->now)->compare($criteria->minPrice) >= 0))
        && ($criteria->maxPrice === null || $any(static fn(Pricing $pricing): bool => $pricing->priceAt($criteria->now)->compare($criteria->maxPrice) <= 0))
        && ($criteria->shippingClass === null || $details->shippingClassId === ($criteria->shippingClass === 0 ? null : $criteria->shippingClass))
        && ($criteria->attributeSet === null || array_filter($product->sets(), static fn(AttributeSet $set): bool => $set->id() === $criteria->attributeSet) !== [])
        && match ($criteria->missing) {
            ProductCriteria::NO_SKU => $details->sku === null || array_filter($variations, static fn(Variation $variation): bool => $variation->sku() === null) !== [],
            ProductCriteria::NO_PRICE => $any(static fn(Pricing $pricing): bool => $pricing->price()->isZero()) && $product->bundle()?->pricing !== Bundle::SUM,
            ProductCriteria::NO_WEIGHT => $details->type === ProductType::Physical && !$product->isBundle() && $details->weight === null
                && ($variations === [] || array_filter($variations, static fn(Variation $variation): bool => $variation->weight() === null) !== []),
            default => true,
        };
    }

    public function highestLowStockThreshold(): ?int
    {
        $thresholds = [];
        foreach ($this->products as $product) {
            $thresholds[] = $product->details()->lowStockThreshold;
            foreach ($product->variations() as $variation) {
                $thresholds[] = $variation->lowStockThreshold();
            }
        }
        $thresholds = array_filter($thresholds, \is_int(...));

        return $thresholds === [] ? null : max($thresholds);
    }

    public function findMany(array $ids): array
    {
        return array_values(array_filter(array_map(fn(int $id): ?Product => $this->products[$id] ?? null, $ids)));
    }

    public function save(Product $product): void
    {
        $this->ids->attributes($product->ownAttributes());
        foreach ($product->downloads() as $file) {
            $this->ids->download($file);
        }
        $this->products[$product->id()] = $product;
    }

    public function remove(Product $product): void
    {
        unset($this->products[$product->id()]);
    }

    public function forgetShippingClass(int $shippingClassId): void
    {
        foreach ($this->products as $product) {
            if ($product->details()->shippingClassId === $shippingClassId) {
                $product->leaveShippingClass();
            }
        }
    }

    public function isSkuTaken(string $sku, ?int $exceptProductId = null, ?int $exceptVariationId = null): bool
    {
        foreach ($this->products as $product) {
            if ($product->id() !== $exceptProductId && $product->details()->sku === $sku) {
                return true;
            }
            foreach ($product->variations() as $variation) {
                if ($variation->id() !== $exceptVariationId && $variation->sku() === $sku) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isAnyOptionUsedByVariations(array $optionIds): bool
    {
        foreach ($this->products as $product) {
            foreach ($product->variations() as $variation) {
                if (array_intersect($variation->signature(), $optionIds) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isSetUsedByVariations(AttributeSet $set): bool
    {
        $optionIds = [];
        foreach ($set->ownAttributes() as $attribute) {
            $optionIds = [...$optionIds, ...$attribute->savedOptionIds()];
        }

        return $this->isAnyOptionUsedByVariations($optionIds);
    }
}
