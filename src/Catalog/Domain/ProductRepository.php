<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

interface ProductRepository
{
    public function find(int $id): ?Product;

    /**
     * Saves the product with its attributes and variations; new ones have their ids afterwards.
     */
    public function save(Product $product): void;

    public function remove(Product $product): void;

    /**
     * Whether a product or variation other than the given ones has this SKU.
     */
    public function isSkuTaken(string $sku, ?int $exceptProductId = null, ?int $exceptVariationId = null): bool;

    /**
     * Whether any product's variation uses one of these options.
     *
     * @param list<int> $optionIds
     */
    public function isAnyOptionUsedByVariations(array $optionIds): bool;

    /**
     * Whether any product with variations uses the set.
     */
    public function isSetUsedByVariations(AttributeSet $set): bool;
}
