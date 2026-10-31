<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * What a product is, as its page lists it: its attributes with their options, in the shopper's language, and its
 * weight and size in the shop's units; and whether it ships, for the delivery estimate.
 */
final readonly class ProductSpecificationsView
{
    /**
     * @param list<array{name: string, values: list<string>}> $attributes those the specifications list, in display order
     * @param string|null                                     $weight     in $weightUnit, as a decimal string
     * @param string|null                                     $length     in $dimensionUnit, as are width and height
     */
    public function __construct(
        public array $attributes,
        public ?string $weight,
        public ?string $length,
        public ?string $width,
        public ?string $height,
        public string $weightUnit,
        public string $dimensionUnit,
        public bool $ships,
        public ?int $shippingClassId,
    ) {}
}
