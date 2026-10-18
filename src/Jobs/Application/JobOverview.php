<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

use OrillaCart\Jobs\Application\Dto\ChooseFailedJobs;
use OrillaCart\Jobs\Application\Dto\FailedJobPage;
use OrillaCart\Jobs\Application\Dto\FailedJobQuery;
use OrillaCart\Jobs\Application\Dto\FailedJobView;
use OrillaCart\Jobs\Application\Dto\JobCounts;
use OrillaCart\Jobs\Application\Dto\JobsOverview;
use OrillaCart\Jobs\Application\Dto\LaneView;
use OrillaCart\Jobs\Application\Dto\ScheduledJobView;
use OrillaCart\Jobs\Application\Dto\TypeView;
use OrillaCart\Jobs\Application\Dto\WaitingJobs;
use Psr\Clock\ClockInterface;

/**
 * The Shop → Background jobs screen: what waits and runs, the workers, what failed, and what the jobs did lately.
 * A handful of grouped queries, however many jobs wait.
 */
final readonly class JobOverview
{
    /** WP-Cron runs every minute on a visited site; quiet for longer than this, nothing runs the jobs. */
    private const int CRON_LATE_SECONDS = 300;
    private const int SCHEDULED = 20;

    public function __construct(
        private JobQueue $queue,
        private FailedJobs $failed,
        private JobHandlers $handlers,
        private JobWorkers $workers,
        private Jobs $jobs,
        private ClockInterface $clock,
    ) {}

    public function overview(): JobsOverview
    {
        $now = $this->clock->now();
        $byQueue = $this->queue->waitingByQueue($now);
        $byType = $this->queue->waitingByType($now);
        $types = $this->handlers->types();
        $workers = $this->workers->workers();

        $typesOf = [];
        foreach ($types as $type) {
            $typesOf[$this->handlers->get($type)::queue()][] = $type;
        }
        $lanes = [];
        foreach (array_unique([...$this->handlers->queues(), ...array_map(strval(...), array_keys($byQueue))]) as $queue) {
            $waiting = $byQueue[$queue] ?? new WaitingJobs();
            $lanes[] = new LaneView($queue, $typesOf[$queue] ?? [], $waiting->due, $waiting->running, $waiting->later, $waiting->oldestAt, $waiting->nextAt, $this->workers->serving($queue));
        }
        $typeViews = array_map(function (string $type) use ($byType): TypeView {
            $waiting = $byType[$type] ?? new WaitingJobs();

            return new TypeView($type, $this->handlers->get($type)::queue(), $waiting->due, $waiting->running, $waiting->later, $this->failed->count($type));
        }, $types);

        $cronRanAt = $this->workers->cronRanAt();
        $runner = match (true) {
            $workers !== [] => 'workers',
            $cronRanAt !== null && $now->getTimestamp() - $cronRanAt->getTimestamp() <= self::CRON_LATE_SECONDS => 'cron',
            default => 'none',
        };
        $hours = $this->queue->history($now->setTime((int) $now->format('H'), 0)->modify('-23 hours'));

        return new JobsOverview(
            $lanes,
            $typeViews,
            $workers,
            $runner,
            $cronRanAt?->format(\DATE_ATOM),
            $this->failed->count(),
            array_map(fn(ScheduledJobView $job): ScheduledJobView => $job->about($this->about($job->type, $job->payload, $job->uniqueKey)), $this->queue->scheduled($now, self::SCHEDULED)),
            $hours,
            self::byDay($this->queue->history($now->setTime(0, 0)->modify('-29 days'))),
            $now->format(\DATE_ATOM),
        );
    }

    public function failed(FailedJobQuery $query): FailedJobPage
    {
        $type = $query->type === '' ? null : $query->type;
        $total = $this->failed->count($type);
        $jobs = array_map(
            fn(FailedJobView $job): FailedJobView => $job->about($this->about($job->type, $job->payload, $job->uniqueKey)),
            $this->failed->list($type, $query->perPage, ($query->page - 1) * $query->perPage),
        );

        return new FailedJobPage($jobs, $total, $query->page, max(1, (int) ceil($total / $query->perPage)));
    }

    /** @return int how many */
    public function retry(ChooseFailedJobs $command): int
    {
        return $this->jobs->retryFailed($command->all ? null : $command->ids);
    }

    /** @return int how many */
    public function forget(ChooseFailedJobs $command): int
    {
        return $this->jobs->forgetFailed($command->all ? null : $command->ids);
    }

    /** Asks every running worker to finish its batch and stop; their supervisor starts them again. */
    public function restartWorkers(): void
    {
        $this->workers->restart();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function about(string $type, array $payload, ?string $uniqueKey): string
    {
        try {
            return $this->handlers->get($type)->describe($payload, $uniqueKey);
        } catch (\LogicException) {
            return $uniqueKey ?? '';
        }
    }

    /**
     * @param list<JobCounts> $hours
     *
     * @return list<JobCounts>
     */
    private static function byDay(array $hours): array
    {
        $days = [];
        foreach ($hours as $hour) {
            $key = substr($hour->at, 0, 10) . '|' . $hour->type;
            $jobs = $hour->done + $hour->retried + $hour->failed;
            $day = $days[$key] ?? ['at' => new \DateTimeImmutable(substr($hour->at, 0, 10), new \DateTimeZone('UTC'))->format(\DATE_ATOM), 'type' => $hour->type, 'done' => 0, 'retried' => 0, 'failed' => 0, 'ms' => 0];
            $day['done'] += $hour->done;
            $day['retried'] += $hour->retried;
            $day['failed'] += $hour->failed;
            $day['ms'] += $hour->averageMs * $jobs;
            $days[$key] = $day;
        }

        return array_values(array_map(static function (array $day): JobCounts {
            $jobs = $day['done'] + $day['retried'] + $day['failed'];

            return new JobCounts($day['at'], $day['type'], $day['done'], $day['retried'], $day['failed'], $jobs === 0 ? 0 : intdiv($day['ms'], $jobs));
        }, $days));
    }
}
