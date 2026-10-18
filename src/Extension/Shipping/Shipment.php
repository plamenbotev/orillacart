<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

/**
 * A parcel to price: what ships, where to, and in which units the shop measures.
 */
final readonly class Shipment
{
    /**
     * @param string             $country       ISO 3166-1 alpha-2: "BG"
     * @param string|null        $state         the country's subdivision code, where it has them
     * @param list<ShipmentItem> $items
     * @param string             $subtotal      what the items cost before tax: "42.50"
     * @param string             $weightUnit    "kg", "g", "lb" or "oz"
     * @param string             $dimensionUnit "cm", "m", "mm" or "in"
     */
    public function __construct(
        public string $country,
        public ?string $state,
        public ?string $postcode,
        public array $items,
        public string $subtotal,
        public string $currency,
        public string $weightUnit,
        public string $dimensionUnit,
    ) {}

    /** The weight of everything, in the shop's unit: "3.5". */
    public function weight(): string
    {
        $total = 0.0;
        foreach ($this->items as $item) {
            $total += $item->quantity * (float) ($item->weight ?? '0');
        }

        return rtrim(rtrim(number_format($total, 4, '.', ''), '0'), '.');
    }
}
