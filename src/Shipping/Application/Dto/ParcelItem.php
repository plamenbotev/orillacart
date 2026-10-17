<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * An item that ships, as its product page measures one unit: weight in the shop's weight unit, length,
 * width and height in its dimension unit. A missing measure counts as zero.
 */
final readonly class ParcelItem
{
    public function __construct(
        public int $quantity,
        public ?string $weight = null,
        public ?string $length = null,
        public ?string $width = null,
        public ?string $height = null,
    ) {}
}
