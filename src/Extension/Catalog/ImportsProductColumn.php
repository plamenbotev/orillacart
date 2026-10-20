<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * A product column the import reads: after the shop saved a row's product or variation, the add-on saves its
 * value. A file without the column leaves the add-on's data as it is.
 */
interface ImportsProductColumn extends ProductColumn
{
    /**
     * @throws \InvalidArgumentException for a value the add-on refuses; its message is reported with the row, whose
     *                                   product the shop has saved already
     */
    public function import(ProductItem $item, string $value): void;
}
