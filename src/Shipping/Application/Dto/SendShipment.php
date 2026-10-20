<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\Consignment;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: record a shipment the shop sent itself, with whom, and each parcel's tracking number.
 */
final readonly class SendShipment
{
    /**
     * @param list<SaveParcel> $parcels at least one
     * @param list<ShipItem>   $items   what it carries; empty for everything left to send
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $carrier,
        #[Assert\Valid]
        #[Assert\Count(min: 1, max: Consignment::MAX_PARCELS)]
        public array $parcels,
        #[Assert\Valid]
        #[Assert\Count(max: 500)]
        public array $items = [],
    ) {}
}
