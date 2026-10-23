<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * The shop's products, for add-ons marking them for their filters (ProductFilter). Since version 8.
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
}
