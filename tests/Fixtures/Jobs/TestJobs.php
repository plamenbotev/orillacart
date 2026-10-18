<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Jobs;

use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobHandlers;
use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Jobs\Application\JobWorkers;
use OrillaCart\Shared\Lifecycle\AfterResponse;
use OrillaCart\Shared\Persistence\LoadedEntities;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;
use Symfony\Component\Clock\MockClock;

/**
 * The job system in memory, with the handlers a test gives it. Work deferred to the end of the request waits in
 * $deferred until the test runs it.
 */
final class TestJobs implements JobHandlers, AfterResponse
{
    public readonly MemoryJobQueue $queue;
    public readonly MemoryWorkerRegistry $registry;
    public readonly MemoryLogger $logger;
    public readonly JobWorkers $workers;
    public readonly JobRunner $runner;
    public readonly Jobs $jobs;
    /** @var array<string, \Closure> */
    public array $deferred = [];
    /** Runs deferred work at once, as if every request ended right after pushing: for tests of other areas. */
    public bool $immediate = false;
    /** @var array<string, JobHandler> */
    private array $handlers = [];

    public function __construct(public readonly MockClock $clock = new MockClock('2030-01-01 12:00:00'))
    {
        $this->queue = new MemoryJobQueue();
        $this->registry = new MemoryWorkerRegistry();
        $this->logger = new MemoryLogger();
        $this->workers = new JobWorkers($this->registry, $this->clock);
        $this->runner = new JobRunner($this->queue, $this, new class implements LoadedEntities {
            public function forget(): void {}
        }, $this->clock, $this->logger);
        $this->jobs = new Jobs($this->queue, $this, $this->clock, $this, $this->runner, $this->workers, $this->queue->failed, $this->registry);
    }

    public function with(JobHandler ...$handlers): self
    {
        foreach ($handlers as $handler) {
            $this->handlers[$handler::type()] = $handler;
        }

        return $this;
    }

    public function get(string $type): JobHandler
    {
        return $this->handlers[$type] ?? throw new \LogicException(\sprintf('No handler does jobs of type "%s".', $type));
    }

    public function types(): array
    {
        $types = array_keys($this->handlers);
        sort($types);

        return $types;
    }

    public function queues(): array
    {
        $queues = array_values(array_unique(array_map(static fn(JobHandler $handler): string => $handler::queue(), $this->handlers)));
        sort($queues);

        return $queues;
    }

    public function defer(string $key, \Closure $work): void
    {
        if ($this->immediate) {
            $work();

            return;
        }
        $this->deferred[$key] ??= $work;
    }

    /** Runs what was deferred, as the end of the request does. */
    public function endRequest(): void
    {
        $deferred = $this->deferred;
        $this->deferred = [];
        foreach ($deferred as $work) {
            $work();
        }
    }

    /** Runs every due job, as a worker does. */
    public function runAll(): void
    {
        while ($this->runner->work([], 100, 'test')->count() > 0);
    }
}
