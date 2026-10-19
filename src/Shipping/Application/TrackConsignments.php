<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;

/**
 * Asks the couriers where shipments are, one job per shipment on its way, queued every hour (TrackShipments). A
 * courier that cannot answer is asked again after 5, 10 and 20 minutes; the next hour queues it afresh anyway.
 */
final readonly class TrackConsignments implements JobHandler
{
    public const string TYPE = 'shipping.track';

    public function __construct(private Consignments $consignments) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return 'shipping';
    }

    public function maxAttempts(): int
    {
        return 4;
    }

    public function retryAfter(int $attempt): int
    {
        return 300 * 2 ** ($attempt - 1);
    }

    /** Each consignment asks its courier, who may be slow to answer. */
    public function lockSeconds(): int
    {
        return 600;
    }

    public function handle(array $jobs): array
    {
        $outcomes = [];
        foreach ($jobs as $job) {
            try {
                $this->consignments->track((int) $job->uniqueKey);
            } catch (\Throwable $e) {
                $outcomes[$job->id] = JobOutcome::retry($e->getMessage());
            }
        }

        return $outcomes;
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Shipment #%s', $uniqueKey ?? '?');
    }
}
