<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\ClaimedJob;
use OrillaCart\Search\Application\Dto\IndexRun;
use OrillaCart\Search\Application\Dto\SearchDocument;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Domain\SearchEngine;
use OrillaCart\Shared\Persistence\LoadedEntities;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Takes due products from the queue and builds their search documents. Cron, the end of a request that changed
 * products, and WP-CLI run it; any number of them can run at once.
 */
final readonly class IndexWorker
{
    /** How long a worker holds what it took; one that dies leaves it to others after this. */
    public const int LOCK_SECONDS = 300;
    /** A failed build is tried again after this. */
    public const int RETRY_SECONDS = 300;

    public function __construct(
        private IndexQueue $queue,
        private DocumentBuilder $builder,
        private SearchIndex $index,
        private LoadedEntities $entities,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private EngineSettingsRepository $engine,
    ) {}

    public function work(int $limit = 50): IndexRun
    {
        $now = $this->clock->now();
        $jobs = $this->queue->claim($limit, $now, $now->modify(\sprintf('+%d seconds', self::LOCK_SECONDS)));
        if ($jobs === []) {
            $this->markBuilt();

            return new IndexRun();
        }
        // Read the products as they are now, not as this process loaded them before.
        $this->entities->forget();

        try {
            return $this->batch($jobs);
        } catch (\Throwable) {
            // One product spoiled the batch: build them one by one, so only that one fails.
            return $this->oneByOne($jobs);
        }
    }

    /**
     * The batch's documents read and written together: a few queries and one write for all of them.
     *
     * @param list<ClaimedJob> $jobs
     */
    private function batch(array $jobs): IndexRun
    {
        $documents = $this->builder->buildMany(array_map(static fn(ClaimedJob $job): int => $job->productId, $jobs));
        $built = array_values(array_filter($documents));
        $gone = array_map(intval(...), array_keys(array_filter($documents, static fn(?SearchDocument $document): bool => $document === null)));
        $this->index->put($built);
        $this->index->remove($gone);
        foreach ($jobs as $job) {
            $this->queue->complete($job, $documents[$job->productId]?->priceChangesAt);
        }

        return new IndexRun(\count($built), \count($gone));
    }

    /**
     * @param list<ClaimedJob> $jobs
     */
    private function oneByOne(array $jobs): IndexRun
    {
        $built = $removed = $failed = 0;
        foreach ($jobs as $job) {
            try {
                $document = $this->builder->build($job->productId);
                if ($document === null) {
                    $this->index->remove([$job->productId]);
                    $this->queue->complete($job, null);
                    ++$removed;
                } else {
                    $this->index->put([$document]);
                    $this->queue->complete($job, $document->priceChangesAt);
                    ++$built;
                }
            } catch (\Throwable $e) {
                $this->logger->error('The search document of product {product} could not be built: {message}', ['product' => $job->productId, 'message' => $e->getMessage(), 'exception' => $e]);
                $this->queue->fail($job, $e->getMessage(), $this->clock->now()->modify(\sprintf('+%d seconds', self::RETRY_SECONDS)));
                ++$failed;
            }
        }

        return new IndexRun($built, $removed, $failed);
    }

    /**
     * OpenSearch answers shoppers once nothing is left to build after switching to it.
     */
    private function markBuilt(): void
    {
        $settings = $this->engine->load();
        if ($settings->engine === SearchEngine::OpenSearch && !$settings->ready && $this->queue->status($this->clock->now())->due === 0) {
            $this->engine->save($settings->built());
        }
    }
}
