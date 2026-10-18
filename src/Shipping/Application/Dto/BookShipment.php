<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: book a shipment for the order with its carrier's courier.
 */
final readonly class BookShipment
{
    public function __construct(
        /** How many parcels the shop packs the order in. */
        #[Assert\Range(min: 1, max: 50)]
        public int $parcels = 1,
    ) {}
}
