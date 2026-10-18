<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * A way checkout can offer to ship a parcel, priced for it before tax: a rate of the shop's rate table, or a
 * service a carrier's shipping method quoted.
 */
final readonly class ShippingOptionView
{
    /** "rate:12" for a rate, "carrier:5:express" for a service carrier 5's method quoted. */
    public const string ID_PATTERN = '/^(rate:[1-9]\d{0,9}|carrier:[1-9]\d{0,9}:[a-z0-9_]{1,40})$/';

    public function __construct(
        public string $id,
        public int $carrierId,
        public string $carrier,
        public string $name,
        public string $price,
        public bool $taxable,
        public ?int $taxGroupId,
        /** The service delivers to a pickup point the customer chooses. */
        public bool $toPickupPoint = false,
    ) {}
}
