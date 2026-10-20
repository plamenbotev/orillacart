<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

/**
 * One parcel of a shipment: its tracking number, and where the customer follows it.
 */
final readonly class ParcelTracking
{
    public string $number;

    public function __construct(
        string $number,
        public ?string $trackingUrl = null,
    ) {
        $number = trim($number);
        if ($number === '' || mb_strlen($number) > 64) {
            throw new ShippingRuleViolation('A parcel needs a tracking number of up to 64 characters.', 'orillacart_invalid_tracking_number');
        }
        $this->number = $number;
    }
}
