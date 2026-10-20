<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

/**
 * A shipment the courier booked.
 */
final readonly class BookedShipment
{
    /**
     * @param string      $number      the courier's number for it, which its label, tracking and cancelling use; up to 64 characters
     * @param string|null  $trackingUrl where the customer follows it
     * @param list<string> $parcels     each parcel's own number, when the courier gives one each (since version 5)
     */
    public function __construct(
        public string $number,
        public ?string $trackingUrl = null,
        public array $parcels = [],
    ) {
        if ($number === '' || mb_strlen($number) > 64) {
            throw new \InvalidArgumentException('A shipment needs a number of up to 64 characters.');
        }
    }
}
