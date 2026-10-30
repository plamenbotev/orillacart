<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Exchange\Domain\ImportNotFound;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use Psr\Clock\ClockInterface;

/**
 * Imports a products file a batch of rows at a time, in a lane of its own: each run imports a batch and comes
 * back for the next at once, so other jobs are not kept waiting behind a large file.
 */
final readonly class ImportProducts implements JobHandler
{
    public const string TYPE = 'exchange.import_products';

    public function __construct(
        private ProductImports $imports,
        private ClockInterface $clock,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return 'imports';
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function retryAfter(int $attempt): int
    {
        return 60;
    }

    public function lockSeconds(): int
    {
        return 300;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        $outcomes = [];
        foreach ($jobs as $job) {
            $id = $job->payload['import'] ?? null;
            if (!\is_int($id)) {
                $outcomes[$job->id] = JobOutcome::fail('The job names no import.');
                continue;
            }
            try {
                if ($this->imports->continue($id)) {
                    $outcomes[$job->id] = JobOutcome::reschedule($this->clock->now());
                }
            } catch (ImportNotFound) {
                // Discarded since.
            }
        }

        return $outcomes;
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Products import %s', \is_int($payload['import'] ?? null) ? $payload['import'] : '?');
    }
}
