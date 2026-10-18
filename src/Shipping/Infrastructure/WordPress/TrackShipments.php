<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Infrastructure\WordPress;

use OrillaCart\Shared\WordPress\ScheduledTask;
use OrillaCart\Shipping\Application\Consignments;

/**
 * Every hour: asks the couriers where the shipments on their way are.
 */
final readonly class TrackShipments implements ScheduledTask
{
    public function __construct(private Consignments $consignments) {}

    public static function hook(): string
    {
        return 'orillacart_track_shipments';
    }

    public static function recurrence(): string
    {
        return 'hourly';
    }

    public function run(): void
    {
        $this->consignments->track();
    }
}
