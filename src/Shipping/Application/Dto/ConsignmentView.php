<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\Consignment;

/**
 * A shipment booked with a courier, as the order screen, the receipt and the emails show it.
 */
final readonly class ConsignmentView
{
    /**
     * @param string $status   booked, on_the_way, delivered, returned or cancelled
     * @param string $tracking the courier's last words about it
     */
    public function __construct(
        public int $id,
        public string $number,
        public string $carrier,
        public ?string $trackingUrl,
        public string $status,
        public string $tracking,
        public string $bookedAt,
    ) {}

    public static function fromConsignment(Consignment $consignment): self
    {
        return new self(
            $consignment->id(),
            $consignment->number(),
            $consignment->carrier(),
            $consignment->trackingUrl(),
            $consignment->status()->value,
            $consignment->tracking(),
            $consignment->bookedAt()->format(\DATE_ATOM),
        );
    }
}
