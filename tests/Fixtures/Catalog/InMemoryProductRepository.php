<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Catalog;

use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Catalog\Domain\ProductRepository;

final class InMemoryProductRepository implements ProductRepository
{
    /** @var array<int, Product> */
    private array $products = [];

    public function __construct(private readonly CatalogIds $ids = new CatalogIds()) {}

    public function find(int $id): ?Product
    {
        return $this->products[$id] ?? null;
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
