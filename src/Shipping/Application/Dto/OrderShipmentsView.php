<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * An order's shipments, what is left to send, and how the shop can send it: booked with its carrier's courier,
 * or sent by the shop itself with tracking numbers it enters.
 */
final readonly class OrderShipmentsView
{
    /**
     * @param bool                  $bookable  the order's carrier books shipments with its courier
     * @param string|null           $carrier   that carrier, or the one the order ships with
     * @param list<ConsignmentView> $shipments oldest first
     * @param list<ShippedItemView> $left      the items not in a shipment yet
     * @param bool                  $sendable  the order can ship what is left
     */
    public function __construct(
        public bool $bookable,
        public ?string $carrier,
        public array $shipments,
        public array $left = [],
        public bool $sendable = false,
    ) {}
}
