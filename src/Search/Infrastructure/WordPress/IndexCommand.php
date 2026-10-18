<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Search\Application\IndexWorker;
use OrillaCart\Search\Application\IndexWorkers;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Shared\WordPress\CliCommand;

/**
 * Builds and inspects the storefront's search index.
 */
final readonly class IndexCommand implements CliCommand
{
    public function __construct(
        private IndexWorker $worker,
        private SearchIndexing $indexing,
        private IndexWorkers $workers,
    ) {}

    public static function name(): string
    {
        return 'orillacart index';
    }

    /**
     * Builds the search documents of the queued products. With --loop it keeps running and waits for work, for a
     * supervisor (Supervisor, systemd) to keep alive: run several to build more at once; each takes different
     * products. It stops cleanly on SIGTERM or SIGINT, after `wp orillacart index restart`, or at a limit, and
     * the supervisor starts a fresh one.
     *
     * ## OPTIONS
     *
     * [--loop]
     * : Keep running and wait for work.
     *
     * [--batch=<count>]
     * : How many products to take at a time.
     * ---
     * default: 100
     * ---
     *
     * [--sleep=<seconds>]
     * : With --loop, how long to wait when the queue is empty.
     * ---
     * default: 3
     * ---
     *
     * [--max-jobs=<count>]
     * : Stop after building this many products.
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
     *     # Build what is queued now, then stop:
     *     wp orillacart index work
     *
     *     # A worker for Supervisor, restarted every hour:
     *     wp orillacart index work --loop --max-time=3600
     *
     * @param list<string>                 $args
     * @param array<string, string|bool>   $options
     */
    public function work(array $args, array $options): void
    {
        $batch = max(1, (int) ($options['batch'] ?? 100));
        $sleep = max(1, (int) ($options['sleep'] ?? 3));
        $loop = ($options['loop'] ?? false) !== false;
        $maxJobs = isset($options['max-jobs']) ? max(1, (int) $options['max-jobs']) : null;
        $maxTime = isset($options['max-time']) ? max(1, (int) $options['max-time']) : null;
        $memory = max(32, (int) ($options['memory'] ?? 256)) * 1024 * 1024;

        $startedAt = new \DateTimeImmutable();
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
        $heartbeat = 0.0;
        while (true) {
            if ($loop && microtime(true) - $heartbeat >= IndexWorkers::HEARTBEAT_SECONDS) {
                $this->workers->heartbeat($name);
                $heartbeat = microtime(true);
            }
            // WordPress keeps posts and terms for the life of the process: read them afresh for each batch.
            if ($loop && \function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
            $run = $this->worker->work($batch);
            $done += $run->count();
            if ($run->count() > 0) {
                \WP_CLI::log(\sprintf('Built %d, removed %d, failed %d.', $run->built, $run->removed, $run->failed));
            }

            $reason = match (true) {
                $stop => 'asked to stop',
                $maxJobs !== null && $done >= $maxJobs => "built {$done} products",
                $maxTime !== null && microtime(true) - $started >= $maxTime => "ran {$maxTime} seconds",
                memory_get_usage(true) > $memory => 'reached its memory limit',
                $loop && $this->workers->shouldRestart($startedAt) => 'asked to restart',
                !$loop && $run->count() === 0 => null,
                default => false,
            };
            if ($reason === null) {
                \WP_CLI::success('The search index is up to date.');

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
     * Queues every product, so the workers build the whole index again.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $options
     */
    public function rebuild(array $args, array $options): void
    {
        \WP_CLI::success(\sprintf('Queued %d products; run `wp orillacart index work` or wait for cron.', $this->indexing->queueEverything()));
    }

    /**
     * Shows how far behind the search index is.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $options
     */
    public function status(array $args, array $options): void
    {
        $status = $this->indexing->status();
        \WP_CLI::log(\sprintf('Due now: %d', $status->due));
        \WP_CLI::log(\sprintf('Waiting for a sale to start or end: %d%s', $status->later, $status->nextAt === null ? '' : ' (next ' . $status->nextAt->format('Y-m-d H:i') . ' UTC)'));
        \WP_CLI::log(\sprintf('Failed too often: %d', \count($status->parked)));
        \WP_CLI::log(\sprintf('Workers running: %d', $this->workers->running()));
        foreach ($status->parked as $productId => $error) {
            \WP_CLI::log(\sprintf('  product %d: %s', $productId, $error));
        }
    }
}
