<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure\WordPress;

use OrillaCart\Jobs\Application\Dto\JobRun;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\FailedJobs;
use OrillaCart\Jobs\Application\JobHandlers;
use OrillaCart\Jobs\Application\JobQueue;
use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Jobs\Application\JobSites;
use OrillaCart\Jobs\Application\JobWorkers;
use OrillaCart\Shared\WordPress\CliCommand;
use Psr\Clock\ClockInterface;

/**
 * Runs and inspects the shop's background jobs: search indexing, emails and the rest.
 */
final readonly class JobsCommand implements CliCommand
{
    public function __construct(
        private JobRunner $runner,
        private JobWorkers $workers,
        private JobHandlers $handlers,
        private JobQueue $queue,
        private FailedJobs $failed,
        private Jobs $jobs,
        private ClockInterface $clock,
        private JobSites $sites,
    ) {}

    public static function name(): string
    {
        return 'orillacart jobs';
    }

    /**
     * Runs due jobs. With --loop it keeps running and waits for work, for a supervisor (Supervisor, systemd) to
     * keep alive: run several to do more at once; each takes different jobs. It stops cleanly on SIGTERM or
     * SIGINT, after `wp orillacart jobs restart`, or at a limit, and the supervisor starts a fresh one.
     *
     * ## OPTIONS
     *
     * [--loop]
     * : Keep running and wait for work.
     *
     * [--network]
     * : Serve every site of the network that runs the shop, a batch from each in turn, instead of the site --url
     * names: one cron line or one worker for the whole network.
     *
     * [--queue=<lanes>]
     * : Serve these lanes only, comma-separated, such as search,mail. All lanes when left out.
     *
     * [--batch=<count>]
     * : How many jobs to take at a time.
     * ---
     * default: 100
     * ---
     *
     * [--sleep=<seconds>]
     * : With --loop, how long to wait when nothing is due.
     * ---
     * default: 3
     * ---
     *
     * [--max-jobs=<count>]
     * : Stop after this many jobs.
     *
     * [--max-time=<seconds>]
     * : Stop after running this long.
     *
     * [--memory=<megabytes>]
     * : Stop when the process uses more memory than this.
     * ---
     * default: 256
     * ---
     *
     * ## EXAMPLES
     *
     *     # Run what is due now, then stop:
     *     wp orillacart jobs work
     *
     *     # A worker for Supervisor, restarted every hour:
     *     wp orillacart jobs work --loop --max-time=3600
     *
     *     # A worker for emails only:
     *     wp orillacart jobs work --loop --queue=mail
     *
     *     # One worker for every shop of a network:
     *     wp orillacart jobs work --network --loop --max-time=3600
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $options
     */
    public function work(array $args, array $options): void
    {
        $queues = \is_string($options['queue'] ?? null) ? array_values(array_filter(array_map(trim(...), explode(',', $options['queue'])), static fn(string $lane): bool => $lane !== '')) : [];
        $unknown = array_diff($queues, $this->handlers->queues());
        if ($unknown !== []) {
            \WP_CLI::error(\sprintf('No such lane: %s. The lanes are %s.', implode(', ', $unknown), implode(', ', $this->handlers->queues())));
        }
        $batch = max(1, (int) ($options['batch'] ?? 100));
        $sleep = max(1, (int) ($options['sleep'] ?? 3));
        $loop = ($options['loop'] ?? false) !== false;
        $maxJobs = isset($options['max-jobs']) ? max(1, (int) $options['max-jobs']) : null;
        $maxTime = isset($options['max-time']) ? max(1, (int) $options['max-time']) : null;
        $memory = max(32, (int) ($options['memory'] ?? 256)) * 1024 * 1024;

        $network = ($options['network'] ?? false) !== false;
        if ($network && !$this->sites->network()) {
            \WP_CLI::error('This site is not a network: run it without --network.');
        }

        $startedAt = $this->clock->now();
        $started = microtime(true);
        $name = gethostname() . ':' . getmypid();
        $stop = false;
        if ($loop && \function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
                pcntl_signal($signal, static function () use (&$stop): void {
                    $stop = true;
                });
            }
        }

        $done = 0;
        /** @var array<string, float> $heartbeats when each site last heard from the worker */
        $heartbeats = [];
        $beat = function (JobWorkers $workers, string $site, ?string $working, bool $force) use ($loop, $name, $queues, $startedAt, &$done, &$heartbeats): void {
            if ($loop && ($force || microtime(true) - ($heartbeats[$site] ?? 0.0) >= JobWorkers::HEARTBEAT_SECONDS)) {
                $workers->heartbeat($name, $queues, $startedAt, $working, $done, memory_get_usage(true));
                $heartbeats[$site] = microtime(true);
            }
        };
        // One batch of a site's queue; with it, whether the site asked its workers to restart.
        $serve = function (JobRunner $runner, JobWorkers $workers, string $site) use ($beat, $queues, $batch, $name, $loop, $startedAt): array {
            $beat($workers, $site, null, false);
            $run = $runner->work($queues, $batch, $name, static function (array $jobs) use ($beat, $workers, $site): void {
                $beat($workers, $site, implode(', ', array_unique(array_map(static fn(QueuedJob $job): string => $job->type, $jobs))), false);
            });
            // What the batch logged is written now, not when the worker stops hours later.
            do_action('orillacart_jobs_worked', $run);

            return [$run, $loop && $workers->shouldRestart($startedAt)];
        };
        $sites = [];
        $sitesAt = 0.0;
        try {
            while (true) {
                // WordPress keeps posts, terms and options for the life of the process: read them afresh each batch.
                if ($loop && \function_exists('wp_cache_flush_runtime')) {
                    wp_cache_flush_runtime();
                }
                if ($network) {
                    // Sites come and go on a network: look again now and then.
                    if (microtime(true) - $sitesAt >= 60) {
                        $sites = $this->sites->sites();
                        $sitesAt = microtime(true);
                    }
                    $run = new JobRun();
                    $restart = false;
                    foreach ($sites as $site) {
                        if ($stop) {
                            break;
                        }
                        [$ran, $asked] = $this->sites->on($site, static fn(JobRunner $runner, JobWorkers $workers): array => $serve($runner, $workers, (string) $site));
                        $restart = $restart || $asked;
                        if ($ran->count() > 0) {
                            \WP_CLI::log(\sprintf('Site #%d: done %d, to retry %d, failed %d.', $site, $ran->done, $ran->retried, $ran->failed));
                        }
                        $run = new JobRun($run->done + $ran->done, $run->retried + $ran->retried, $run->failed + $ran->failed);
                    }
                } else {
                    [$run, $restart] = $serve($this->runner, $this->workers, '');
                    if ($run->count() > 0) {
                        \WP_CLI::log(\sprintf('Done %d, to retry %d, failed %d.', $run->done, $run->retried, $run->failed));
                    }
                }
                $done += $run->count();

                $reason = match (true) {
                    $stop => 'asked to stop',
                    $maxJobs !== null && $done >= $maxJobs => "ran {$done} jobs",
                    $maxTime !== null && microtime(true) - $started >= $maxTime => "ran {$maxTime} seconds",
                    memory_get_usage(true) > $memory => 'reached its memory limit',
                    $restart => 'asked to restart',
                    !$loop && $run->count() === 0 => null,
                    default => false,
                };
                if ($reason === null) {
                    \WP_CLI::success('Nothing is due.');

                    return;
                }
                if ($reason !== false) {
                    \WP_CLI::log("The worker stops: {$reason}.");

                    return;
                }
                if ($run->count() === 0) {
                    sleep($sleep);
                }
            }
        } finally {
            if ($loop) {
                if ($network) {
                    foreach (array_keys($heartbeats) as $site) {
                        $this->sites->on((int) $site, static fn(JobRunner $runner, JobWorkers $workers) => $workers->stopped($name));
                    }
                } else {
                    $this->workers->stopped($name);
                }
            }
        }
    }

    /**
     * Asks every running worker to finish its batch and stop, as after a deploy; the supervisor starts them on the
     * new code.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $options
     */
    public function restart(array $args, array $options): void
    {
        $this->workers->restart();
        \WP_CLI::success('The workers stop after their batch.');
    }

    /**
     * Shows what waits in each lane, the running workers and the failed jobs.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $options
     */
    public function status(array $args, array $options): void
    {
        $waiting = $this->queue->waitingByType($this->clock->now());
        $rows = [];
        foreach ($this->handlers->types() as $type) {
            $lane = $waiting[$type] ?? null;
            $rows[] = [
                'type' => $type,
                'lane' => $this->handlers->get($type)::queue(),
                'due' => $lane->due ?? 0,
                'running' => $lane->running ?? 0,
                'later' => $lane->later ?? 0,
                'failed' => $this->failed->count($type),
            ];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['type', 'lane', 'due', 'running', 'later', 'failed']);

        $workers = $this->workers->workers();
        \WP_CLI::log(\sprintf('Workers running: %d', \count($workers)));
        foreach ($workers as $worker) {
            \WP_CLI::log(\sprintf('  %s (%s): %d jobs, %d MB%s', $worker->name, $worker->queues === [] ? 'all lanes' : implode(', ', $worker->queues), $worker->jobsDone, intdiv($worker->memory, 1024 * 1024), $worker->working === null ? '' : ", on {$worker->working}"));
        }
        $cron = $this->workers->cronRanAt();
        \WP_CLI::log('WP-Cron last ran: ' . ($cron === null ? 'never' : $cron->format('Y-m-d H:i:s') . ' UTC'));
    }

    /**
     * Lists the failed jobs, newest first.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Only jobs of this type, such as search.index.
     *
     * [--limit=<count>]
     * : How many.
     * ---
     * default: 50
     * ---
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $options
     */
    public function failed(array $args, array $options): void
    {
        $type = \is_string($options['type'] ?? null) ? $options['type'] : null;
        $rows = [];
        foreach ($this->failed->list($type, max(1, (int) ($options['limit'] ?? 50))) as $job) {
            $rows[] = ['id' => $job->id, 'type' => $job->type, 'key' => $job->uniqueKey ?? '', 'attempts' => $job->attempts, 'failed_at' => $job->failedAt, 'error' => $job->error];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['id', 'type', 'key', 'attempts', 'failed_at', 'error']);
    }

    /**
     * Puts failed jobs back in their queue with fresh attempts.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : The failed jobs' ids.
     *
     * [--all]
     * : Every failed job.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $options
     */
    public function retry(array $args, array $options): void
    {
        \WP_CLI::success(\sprintf('Queued %d jobs again.', $this->jobs->retryFailed($this->ids($args, $options))));
    }

    /**
     * Deletes failed jobs.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : The failed jobs' ids.
     *
     * [--all]
     * : Every failed job.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $options
     */
    public function forget(array $args, array $options): void
    {
        \WP_CLI::success(\sprintf('Deleted %d failed jobs.', $this->jobs->forgetFailed($this->ids($args, $options))));
    }

    /**
     * @param list<string>               $args
     * @param array<string, string|bool> $options
     *
     * @return list<string>|null
     */
    private function ids(array $args, array $options): ?array
    {
        if (($options['all'] ?? false) !== false) {
            return null;
        }
        if ($args === []) {
            \WP_CLI::error('Name the failed jobs by id, or pass --all.');
        }

        return array_values(array_filter($args, is_numeric(...)));
    }
}
