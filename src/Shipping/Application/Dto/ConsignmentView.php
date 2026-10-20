<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\Consignment;
use OrillaCart\Shipping\Domain\ParcelTracking;

/**
 * A shipment of an order, as the order screen, the receipt and the emails show it.
 */
final readonly class ConsignmentView
{
    /**
     * @param string                $status   booked, on_the_way, delivered, returned or cancelled
     * @param string                $tracking the courier's last words about it
     * @param list<ShippedItemView> $items    what it carries
     * @param list<array{number: string, tracking_url: ?string}> $parcels each parcel's number, when there is one each
     * @param bool                  $sentByShop the shop sent it itself, and says when it arrived
     */
    public function __construct(
        public int $id,
        public string $number,
        public string $carrier,
        public ?string $trackingUrl,
        public string $status,
        public string $tracking,
        public string $bookedAt,
        public array $items = [],
        public array $parcels = [],
        public bool $sentByShop = false,
    ) {}

    /**
     * @param array<int, string> $names the order's line names, by position
     */
    public static function fromConsignment(Consignment $consignment, array $names = []): self
    {
        $items = [];
        foreach ($consignment->items() as $line => $quantity) {
            $items[] = new ShippedItemView($line, $names[$line] ?? '', $quantity);
        }

        return new self(
            $consignment->id(),
            $consignment->number(),
            $consignment->carrier(),
            $consignment->trackingUrl(),
            $consignment->status()->value,
            $consignment->tracking(),
            $consignment->bookedAt()->format(\DATE_ATOM),
            $items,
            array_map(static fn(ParcelTracking $parcel): array => ['number' => $parcel->number, 'tracking_url' => $parcel->trackingUrl], $consignment->parcels()),
            $consignment->isSentByShop(),
        );
    }
}
