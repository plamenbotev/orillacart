<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

interface ProductRepository
{
    public function find(int $id): ?Product;

    /**
     * @param list<int> $ids
     *
     * @return list<Product> the ones that exist, in no particular order
     */
    public function findMany(array $ids): array;

    /**
     * Products whose SKU, or one of whose variations' SKU, contains the text.
     *
     * @return list<int>
     */
    public function idsWithSku(string $text): array;

    /**
     * Attribute options, of sets and of products, whose own, attribute's or set's name contains the text.
     *
     * @return array{list<AttributeOption>, int} a page of them by owner, attribute and position, and how many match in all
     */
    public function options(?string $text, int $offset, int $limit): array;

    /**
     * Ids of products with commerce details, in ascending order, from after the given id.
     *
     * @return list<int>
     */
    public function ids(int $afterId, int $limit): array;

    /**
     * Ids of the products that offer this attribute option: its product's, or those of the products using its set.
     *
     * @return list<int>
     */
    public function idsOfferingOption(int $optionId): array;

    /**
     * @return list<int> ids of the products the attribute set is assigned to
     */
    public function idsUsingSet(int $setId): array;

    /**
     * @return list<string> the names attributes of products and sets use, each once, by name
     */
    public function attributeNames(): array;

    /** The product a variation belongs to. */
    public function findByVariation(int $variationId): ?Product;

    /**
     * Saves the product with its attributes and variations; new ones have their ids afterwards.
     */
    public function save(Product $product): void;

    public function remove(Product $product): void;

    /** The products in this shipping class leave it. */
    public function forgetShippingClass(int $shippingClassId): void;

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
