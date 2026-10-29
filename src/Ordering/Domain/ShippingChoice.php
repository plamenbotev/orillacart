<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

/**
 * How an order ships, kept by name: the carrier or rate may change or go later. What it costs is the order's
 * shipping line.
 */
final readonly class ShippingChoice
{
    public function __construct(
        /** How checkout named it: "rate:12", or "carrier:5:express" for a carrier's own service. */
        public string $option,
        public string $carrier,
        public string $rate,
        /** The courier's id of the office or locker the customer chose, for a service to one. */
        public ?string $pickupPointId = null,
        /** Its name and address. */
        public ?string $pickupPoint = null,
        /** The carrier, rate and place in the shop's own language, where the shopper's differ (design 51). */
        public ?string $shopCarrier = null,
        public ?string $shopRate = null,
        public ?string $shopPickupPoint = null,
    ) {}

    /** The shop's own words, where they differ from the shopper's. */
    public function shopName(): ?string
    {
        $name = ($this->shopCarrier ?? $this->carrier) . ' – ' . ($this->shopRate ?? $this->rate);

        return $name === "{$this->carrier} – {$this->rate}" ? null : $name;
    }
}
