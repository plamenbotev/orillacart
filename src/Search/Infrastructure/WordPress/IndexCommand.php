<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Search\Application\IndexWorker;
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
    ) {}

    public static function name(): string
    {
        return 'orillacart index';
    }

    /**
     * Builds the search documents of the queued products.
     *
     * ## OPTIONS
     *
     * [--batch=<count>]
     * : How many products to take at a time.
     * ---
     * default: 100
     * ---
     *
     * [--loop]
     * : Keep running and wait for work, for a supervisor or a system service.
     *
     * [--sleep=<seconds>]
     * : With --loop, how long to wait when the queue is empty.
     * ---
     * default: 5
     * ---
     *
     * @param list<string>                 $args
     * @param array<string, string|bool>   $options
     */
    public function work(array $args, array $options): void
    {
        $batch = max(1, (int) ($options['batch'] ?? 100));
        $sleep = max(1, (int) ($options['sleep'] ?? 5));
        $loop = ($options['loop'] ?? false) !== false;
        do {
            $run = $this->worker->work($batch);
            if ($run->count() > 0) {
                \WP_CLI::log(\sprintf('Built %d, removed %d, failed %d.', $run->built, $run->removed, $run->failed));
            } elseif ($loop) {
                sleep($sleep);
            }
        } while ($loop || $run->count() > 0);
        \WP_CLI::success('The search index is up to date.');
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
        foreach ($status->parked as $productId => $error) {
            \WP_CLI::log(\sprintf('  product %d: %s', $productId, $error));
        }
    }
}
