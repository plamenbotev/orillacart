<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * A filter of the products list: "Listed on the marketplace", "Waiting for the supplier". The add-on marks the
 * products it applies to (Products::mark() with the filter's id) and unmarks them when it no longer does; the
 * shop keeps the marks and offers the add-ons' filters in one list.
 */
interface ProductFilter
{
    /** Lowercase letters, digits and underscores, up to 40; unique among product filters. */
    public function id(): string;

    /** As the list offers it: "Listed on the marketplace". */
    public function label(): string;
}
