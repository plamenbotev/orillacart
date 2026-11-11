<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * The shop's products, for add-ons marking them for their filters (ProductFilter, since version 8), reading them
 * (find, since version 22) and finding them (search, since 26).
 */
interface Products
{
    /**
     * Marks the product for the filter with this id; marking it again changes nothing.
     *
     * @param int    $productId the product's WordPress post id
     * @param string $filterId  a ProductFilter's id: lowercase letters, digits and underscores, up to 40
     *
     * @return bool false when the shop has no such product
     *
     * @throws \InvalidArgumentException for a filter id that cannot be one
     */
    public function mark(int $productId, string $filterId): bool;

    /** Takes the mark off; a product without it is left as it is. */
    public function unmark(int $productId, string $filterId): void;

    public function isMarked(int $productId, string $filterId): bool;

    /** The product as shoppers see it now, or null for none such (API 22). */
    public function find(int $productId): ?ProductSummary;

    /**
     * Products by SKU (a variation's finds its product), words in the name, or category, whichever are given and
     * all of them, published or not; at most $limit, newest first (API 26).
     *
     * @return list<ProductSummary>
     */
    public function search(?string $sku = null, ?string $words = null, ?int $categoryId = null, int $limit = 20): array;
}
