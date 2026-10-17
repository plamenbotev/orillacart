<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * Query: which rates can ship these items there. The subtotal is the whole order's value before tax,
 * including items that do not ship.
 */
final readonly class ShippingQuery
{
    /**
     * @param list<ParcelItem> $items the items that ship
     */
    public function __construct(
        public ?string $country,
        public ?string $state,
        public array $items,
        public string $subtotal,
    ) {}
}
