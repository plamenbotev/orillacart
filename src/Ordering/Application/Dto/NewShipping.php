<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

final readonly class NewShipping
{
    public function __construct(
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
}
