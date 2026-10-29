<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\Region;
use OrillaCart\Shipping\Domain\ShippingRate;

final readonly class ShippingRateView
{
    /**
     * @param list<string> $regions
     * @param list<int>    $shippingClasses
     * @param list<string> $postcodes
     * @param list<string> $excludedRegions
     * @param list<string> $excludedPostcodes
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $price,
        public bool $perItem,
        public array $regions,
        public ?RangeView $weight,
        public ?RangeView $volume,
        public ?RangeView $length,
        public ?RangeView $width,
        public ?RangeView $height,
        public ?RangeView $subtotal,
        public bool $taxable,
        public ?int $taxGroupId,
        public array $shippingClasses,
        public array $postcodes,
        public array $excludedRegions,
        public array $excludedPostcodes,
        public ?RangeView $items,
        /** @var array<string, string> the name in the site's other languages, by locale */
        public array $translations = [],
    ) {}

    /**
     * @param array<string, string> $translations
     */
    public static function fromRate(ShippingRate $rate, array $translations = []): self
    {
        $details = $rate->details();

        return new self(
            $rate->id(),
            $details->name,
            (string) $details->price,
            $details->perItem,
            array_map(static fn(Region $region): string => (string) $region, $details->regions),
            RangeView::fromRange($details->weight),
            RangeView::fromRange($details->volume),
            RangeView::fromRange($details->length),
            RangeView::fromRange($details->width),
            RangeView::fromRange($details->height),
            RangeView::fromRange($details->subtotal),
            $details->taxable,
            $details->taxGroupId,
            $details->classes,
            array_map(strval(...), $details->postcodes),
            array_map(strval(...), $details->excludedRegions),
            array_map(strval(...), $details->excludedPostcodes),
            RangeView::fromRange($details->items),
            $translations,
        );
    }
}
