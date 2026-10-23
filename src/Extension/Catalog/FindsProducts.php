<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * Finds products by text the add-on keeps about them, for the search box of the products list: a supplier's
 * code, a barcode. The shop lists what it gives beside what it finds by name and SKU.
 */
interface FindsProducts
{
    /** Lowercase letters, digits and underscores, up to 40; unique among product searches. */
    public function id(): string;

    /**
     * @param string $text what the shop manager typed, trimmed
     *
     * @return list<int> the ids of the products it matches (their WordPress post ids); the shop takes up to 500
     */
    public function findProducts(string $text): array;
}
