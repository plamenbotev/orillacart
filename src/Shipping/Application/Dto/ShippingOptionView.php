<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * A way checkout can offer to ship a parcel, priced for it before tax: a rate of the shop's rate table, a
 * service a carrier's shipping method quoted, or one of the shop's places to collect the order.
 */
final readonly class ShippingOptionView
{
    /** "rate:12" for a rate, "carrier:5:express" for a service carrier 5's method quoted, "pickup:7:sofia" for a place of carrier 7's pickup. */
    public const string ID_PATTERN = '/^(rate:[1-9]\d{0,9}|carrier:[1-9]\d{0,9}:[a-z0-9_]{1,40}|pickup:[1-9]\d{0,9}:[a-z0-9][a-z0-9-]{0,39})$/';

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
        /** The shop's place where the customer collects the order, for a pickup. */
        public ?PickupPointView $collectAt = null,
        /** The carrier's, the rate's and the place's in the shop's own language (design 51): what the order keeps for the shop. */
        public ?string $shopCarrier = null,
        public ?string $shopName = null,
        public ?PickupPointView $shopCollectAt = null,
    ) {}
}
