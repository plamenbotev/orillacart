<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\IndexRun;
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
    ) {}

    public function work(int $limit = 50): IndexRun
    {
        $now = $this->clock->now();
        $jobs = $this->queue->claim($limit, $now, $now->modify(\sprintf('+%d seconds', self::LOCK_SECONDS)));
        if ($jobs === []) {
            return new IndexRun();
        }
        // Read the products as they are now, not as this process loaded them before.
        $this->entities->forget();

        $built = $removed = $failed = 0;
        foreach ($jobs as $job) {
            try {
                $document = $this->builder->build($job->productId);
                if ($document === null) {
                    $this->index->remove([$job->productId]);
                    $this->queue->complete($job, null);
                    ++$removed;
                } else {
                    $this->index->put($document);
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
}
