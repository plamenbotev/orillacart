<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * A rate checkout can offer for a parcel, priced for it before tax.
 */
final readonly class ShippingOptionView
{
    public function __construct(
        public int $rateId,
        public int $carrierId,
        public string $carrier,
        public string $name,
        public string $price,
        public bool $taxable,
        public ?int $taxGroupId,
    ) {}
}
