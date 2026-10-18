<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * What is shipped, as rates measure it: the totals over every item that ships (each unit counted), and the
 * value of the whole order before tax. Items that do not ship, such as downloads, add only to the value.
 */
final readonly class Parcel
{
    /**
     * Measures units as one parcel: weights add up, and so do volumes (length × width × height of each unit)
     * and each of the three sides, as if every unit were laid next to the others.
     *
     * @param list<array{quantity: int, weight: Measure, length: Measure, width: Measure, height: Measure, class?: int}> $units
     *        class: the unit's shipping class, ShippingClass::NONE for none
     */
    public static function of(array $units, Money $subtotal): self
    {
        $weight = $volume = $length = $width = $height = Measure::zero();
        $count = 0;
        $classes = [];
        foreach ($units as $unit) {
            $classes[$unit['class'] ?? ShippingClass::NONE] = true;
            $quantity = $unit['quantity'];
            $weight = $weight->add($unit['weight']->times($quantity));
            $volume = $volume->add($unit['length']->multiply($unit['width'])->multiply($unit['height'])->times($quantity));
            $length = $length->add($unit['length']->times($quantity));
            $width = $width->add($unit['width']->times($quantity));
            $height = $height->add($unit['height']->times($quantity));
            $count += $quantity;
        }

        return new self($weight, $volume, $length, $width, $height, $count, $subtotal, array_keys($classes));
    }

    /**
     * @param list<int> $classes the shipping classes of the items, each once
     */
    public function __construct(
        public Measure $weight,
        public Measure $volume,
        public Measure $length,
        public Measure $width,
        public Measure $height,
        public int $items,
        public Money $subtotal,
        public array $classes = [],
    ) {}
}
