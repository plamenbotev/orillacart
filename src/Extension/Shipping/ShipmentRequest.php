<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

/**
 * What the courier needs to book a shipment for an order.
 */
final readonly class ShipmentRequest
{
    /**
     * @param string      $reference        the shop's reference for the courier: "Order #1042"
     * @param string      $service          the service the customer chose (Rate::$code)
     * @param string      $recipientName    first and last name
     * @param string|null $pickupPointId    the point the customer chose, for a service to one
     * @param string|null $cashOnDelivery   the amount the courier collects, when the customer pays on delivery
     * @param string      $declaredValue    what the items are worth, for the courier's insurance
     * @param int         $parcels          how many parcels the shop packs it in
     */
    public function __construct(
        public string $reference,
        public string $service,
        public string $recipientName,
        public string $phone,
        public string $email,
        public string $street,
        public string $city,
        public string $postcode,
        public string $country,
        public ?string $state,
        public ?string $pickupPointId,
        public Shipment $contents,
        public ?string $cashOnDelivery,
        public string $declaredValue,
        public int $parcels = 1,
    ) {}
}
