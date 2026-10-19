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

    /**
     * The weight of everything: "3.5".
     *
     * @param string|null $unit "kg", "g", "lb" or "oz"; the shop's when null
     */
    public function weight(?string $unit = null): string
    {
        $total = 0.0;
        foreach ($this->items as $item) {
            $total += $item->quantity * (float) ($item->weight ?? '0');
        }

        return Units::decimal($total * Units::weightFactor($this->weightUnit, $unit ?? $this->weightUnit));
    }

    /**
     * What the volume of everything weighs as couriers reckon size (dimensional weight): the volume over the
     * divisor, 5000 cm³ to the kilogram unless the courier says otherwise.
     *
     * @param string|null $unit    the weight unit to answer in; the shop's when null
     * @param int         $divisor cubic centimetres to the kilogram
     */
    public function dimensionalWeight(?string $unit = null, int $divisor = Units::DIMENSIONAL_DIVISOR): string
    {
        $cubicCentimetres = 0.0;
        $cm = Units::lengthFactor($this->dimensionUnit, 'cm');
        foreach ($this->items as $item) {
            $cubicCentimetres += $item->quantity * (float) ($item->length ?? '0') * (float) ($item->width ?? '0') * (float) ($item->height ?? '0') * $cm ** 3;
        }

        return Units::decimal($cubicCentimetres / max(1, $divisor) * Units::weightFactor('kg', $unit ?? $this->weightUnit));
    }

    /** A length of an item in another unit: $shipment->length($item->length, 'cm'). */
    public function length(?string $value, string $unit): ?string
    {
        return $value === null ? null : Units::convert($value, Units::lengthFactor($this->dimensionUnit, $unit));
    }
}
