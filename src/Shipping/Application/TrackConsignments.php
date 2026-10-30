<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;

/**
 * Asks the couriers where shipments are, one job per shipment on its way, queued every hour (TrackShipments). A
 * courier that cannot answer is asked again after 5, 10 and 20 minutes; the next hour queues it afresh anyway. Each
 * shipment is reported as its courier answered.
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

    /** One consignment asks its courier, who may be slow to answer. */
    public function lockSeconds(): int
    {
        return 120;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        // Each shipment is settled as its courier answered, so a worker killed mid-batch asks one again, not all.
        foreach ($jobs as $job) {
            try {
                $this->consignments->track((int) $job->uniqueKey);
            } catch (\Throwable $e) {
                $progress->done($job, JobOutcome::retry($e->getMessage()));
                continue;
            }
            $progress->done($job);
        }

        return [];
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Shipment #%s', $uniqueKey ?? '?');
    }
}
