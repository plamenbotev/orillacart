<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Exchange\Domain\ImportNotFound;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use Psr\Clock\ClockInterface;

/**
 * Imports a products file a batch of rows at a time, in a lane of its own: each run imports a batch and comes
 * back for the next at once, so other jobs are not kept waiting behind a large file. Each job is reported as its
 * batch is imported. A batch that fails is tried again; on its last attempt the import ends as failed.
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

    /** One job: one batch of rows. */
    public function lockSeconds(): int
    {
        return 300;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        foreach ($jobs as $job) {
            $progress->done($job, $this->step($job));
        }

        return [];
    }

    /** One job: the next batch of rows of its import. */
    private function step(QueuedJob $job): JobOutcome
    {
        $id = $job->payload['import'] ?? null;
        if (!\is_int($id)) {
            return JobOutcome::fail('The job names no import.');
        }
        try {
            if ($this->imports->continue($id)) {
                return JobOutcome::reschedule($this->clock->now());
            }
        } catch (ImportNotFound) {
            // Discarded since.
        } catch (\Throwable $e) {
            if ($job->attempts < $this->maxAttempts()) {
                return JobOutcome::retry($e->getMessage());
            }
            // Its last attempt: the import ends, rather than show as running for ever.
            $this->imports->fail($id, $e->getMessage());

            return JobOutcome::fail($e->getMessage());
        }

        return JobOutcome::done();
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Products import %s', \is_int($payload['import'] ?? null) ? $payload['import'] : '?');
    }
}
