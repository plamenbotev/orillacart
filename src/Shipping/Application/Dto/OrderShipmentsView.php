<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * An order's shipments, and whether the shop can book one with its carrier's courier.
 */
final readonly class OrderShipmentsView
{
    /**
     * @param string|null           $carrier   the carrier whose method books shipments; null when the order's way to ship cannot
     * @param list<ConsignmentView> $shipments oldest first
     */
    public function __construct(
        public bool $bookable,
        public ?string $carrier,
        public array $shipments,
    ) {}
}
