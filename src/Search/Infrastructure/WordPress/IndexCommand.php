<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Jobs\Application\JobWorkers;
use OrillaCart\Search\Application\IndexProducts;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Shared\WordPress\CliCommand;

/**
 * Rebuilds and inspects the storefront's search index; its jobs run with the others (wp orillacart jobs work).
 */
final readonly class IndexCommand implements CliCommand
{
    public function __construct(
        private SearchIndexing $indexing,
        private JobWorkers $workers,
    ) {}

    public static function name(): string
    {
        return 'orillacart index';
    }

    /**
     * Queues every product, so the workers build the whole index again.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $options
     */
    public function rebuild(array $args, array $options): void
    {
        \WP_CLI::success(\sprintf('Queued %d products; run `wp orillacart jobs work` or wait for cron.', $this->indexing->queueEverything()));
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
        \WP_CLI::log(\sprintf('Failed: %d (wp orillacart jobs failed --type=search.index)', \count($status->parked)));
        \WP_CLI::log(\sprintf('Workers running: %d', $this->workers->serving(IndexProducts::queue())));
        foreach ($status->parked as $productId => $error) {
            \WP_CLI::log(\sprintf('  product %d: %s', $productId, $error));
        }
    }
}
