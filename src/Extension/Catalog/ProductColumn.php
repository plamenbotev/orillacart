<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * A column of the products file: "Supplier", "Barcode". The shop lists it beside its own on the export screen,
 * where the shop manager chooses the columns to export. A column the import also reads implements
 * ImportsProductColumn.
 */
interface ProductColumn
{
    /** Lowercase letters, digits and underscores, up to 40; unique among product columns, the shop's own too. */
    public function id(): string;

    /** The column's name, for the shop manager and the file's header. */
    public function label(): string;

    /** The product's or variation's value in the column; empty for none. */
    public function value(ProductItem $item): string;
}
