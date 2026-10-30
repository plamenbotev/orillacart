<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Search\Application\Dto\SearchDocument;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Domain\SearchEngine;

/**
 * Builds the search documents of products that changed, a batch at a time: the catalog's offers and the site's
 * content read in a few queries, and one write for all of them. A product with a sale ahead is built again when
 * the sale starts or ends.
 */
final readonly class IndexProducts implements JobHandler
{
    public const string TYPE = 'search.index';

    public function __construct(
        private DocumentBuilder $builder,
        private SearchIndex $index,
        private Jobs $jobs,
        private EngineSettingsRepository $engine,
        private SearchServer $server,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return 'search';
    }

    public function maxAttempts(): int
    {
        return 5;
    }

    public function retryAfter(int $attempt): int
    {
        return 60 * 2 ** ($attempt - 1);
    }

    public function lockSeconds(): int
    {
        return 300;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        $productIds = array_map(static fn(QueuedJob $job): int => (int) $job->uniqueKey, $jobs);
        $documents = $this->builder->buildMany($productIds);
        $this->put(array_values(array_filter($documents)));
        $this->index->remove(array_map(intval(...), array_keys(array_filter($documents, static fn(?SearchDocument $document): bool => $document === null))));

        $outcomes = [];
        foreach ($jobs as $job) {
            $at = $documents[(int) $job->uniqueKey]?->priceChangesAt;
            $outcomes[$job->id] = $at === null ? JobOutcome::done() : JobOutcome::reschedule($at);
        }
        // This batch was the last of the index: nothing else due, running with another worker, or failed.
        $waiting = $this->jobs->waiting(self::TYPE);
        if ($waiting->due === 0 && $waiting->running <= \count($jobs) && $this->jobs->failed(self::TYPE, 1) === []) {
            $this->markBuilt();
        }

        return $outcomes;
    }

    /**
     * An index behind this version refuses the documents' new fields: it is given them, and the batch again. When
     * it cannot be, the batch fails with the reason, and is tried again later.
     *
     * @param list<SearchDocument> $documents
     */
    private function put(array $documents): void
    {
        try {
            $this->index->put($documents);
        } catch (IndexRefusedFields $refused) {
            $problem = $this->server->prepare($this->engine->load());
            if ($problem !== null) {
                throw new \RuntimeException($refused->getMessage() . ' The index could not be prepared: ' . $problem, 0, $refused);
            }
            $this->index->put($documents);
        }
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Product #%s', $uniqueKey ?? '?');
    }

    /**
     * OpenSearch answers shoppers once nothing is left to build after switching to it, and nothing failed: a
     * product that could not be indexed would be missing from what shoppers find.
     */
    public function markBuilt(): void
    {
        $settings = $this->engine->load();
        if ($settings->engine === SearchEngine::OpenSearch && !$settings->ready) {
            $this->engine->save($settings->built());
        }
    }
}
