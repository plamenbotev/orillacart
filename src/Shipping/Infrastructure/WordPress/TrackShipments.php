<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Infrastructure\WordPress;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Shared\WordPress\ScheduledTask;
use OrillaCart\Shipping\Application\Consignments;
use OrillaCart\Shipping\Application\TrackConsignments;

/**
 * Every hour: queues a tracking job for each shipment on its way.
 */
final readonly class TrackShipments implements ScheduledTask
{
    public function __construct(
        private Consignments $consignments,
        private Jobs $jobs,
    ) {}

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
        $this->jobs->pushMany(TrackConsignments::TYPE, array_map(static fn(int $id): array => ['key' => (string) $id, 'payload' => []], $this->consignments->onTheWay()));
    }
}
