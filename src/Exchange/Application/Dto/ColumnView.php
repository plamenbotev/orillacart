<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application\Dto;

/**
 * A column the shop can export, the shop's own or an add-on's, and whether the shop manager chose it.
 *
 * @param bool $importable for products: whether the import reads it back
 */
final readonly class ColumnView
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $addOn,
        public bool $chosen,
        public bool $importable = false,
    ) {}
}
