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
