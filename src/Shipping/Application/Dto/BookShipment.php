<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: book a shipment for the order with its carrier's courier: some of its items, or all that have not
 * gone yet.
 */
final readonly class BookShipment
{
    /**
     * @param list<ShipItem> $items what it carries; empty for everything left to send
     */
    public function __construct(
        /** How many parcels the shop packs it in. */
        #[Assert\Range(min: 1, max: 50)]
        public int $parcels = 1,
        #[Assert\Valid]
        #[Assert\Count(max: 500)]
        public array $items = [],
    ) {}
}
