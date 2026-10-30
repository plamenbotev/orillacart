<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Jobs;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobProgress;

/**
 * A JobProgress for calling a handler directly: keeps what the handler reported, by job id.
 */
final class RecordedProgress
{
    public readonly JobProgress $progress;
    /** @var array<string, JobOutcome> by job id */
    public array $outcomes = [];
    public int $holds = 0;

    public function __construct()
    {
        $this->progress = new JobProgress(function (QueuedJob $job, JobOutcome $outcome): void {
            $this->outcomes[$job->id] = $outcome;
        }, function (): void {
            ++$this->holds;
        });
    }

    /** One for a handler whose reports a test does not read. */
    public static function none(): JobProgress
    {
        return new self()->progress;
    }

    /**
     * The outcomes of a call: what the handler returned and what it reported, together.
     *
     * @param array<string, JobOutcome> $returned
     *
     * @return array<string, JobOutcome>
     */
    public function with(array $returned): array
    {
        return $returned + $this->outcomes;
    }
}
