<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Search\Application\Dto\QueueStatus;

/**
 * Keeps the search index in step with the catalog: queues the products that changed, or all of them, as
 * search.index jobs, one per product however often it changes before it is built.
 */
final readonly class SearchIndexing
{
    private const int PAGE = 500;

    public function __construct(
        private Jobs $jobs,
        private ProductLookup $products,
        private IndexProducts $handler,
        private SearchIndex $index,
    ) {}

    /**
     * @param list<int> $productIds
     */
    public function queue(array $productIds): void
    {
        $this->jobs->pushMany(IndexProducts::TYPE, array_map(
            static fn(int $id): array => ['key' => (string) $id, 'payload' => []],
            // A bundle's price and stock follow its items'.
            array_values(array_unique([...$productIds, ...$this->products->bundlesHolding($productIds)])),
        ));
    }

    /**
     * Queues the products a price rule is for: those named, and those in its categories or brands.
     *
     * @param list<int> $productIds
     * @param list<int> $categoryIds
     * @param list<int> $brandIds
     */
    public function queuePricedFor(array $productIds, array $categoryIds, array $brandIds): void
    {
        $inTerms = $categoryIds === [] && $brandIds === [] ? [] : $this->products->idsInTerms($categoryIds, $brandIds);
        $ids = array_values(array_unique([...$productIds, ...$inTerms]));
        if ($ids !== []) {
            $this->queue($ids);
        }
    }

    /** Stock of a product, variation or option changed: "variation:12", as StockChanged names it. */
    public function queueStockItem(string $item): void
    {
        $this->queue($this->products->idsOfStockItem(StockItemRef::fromString($item)));
    }

    /**
     * Queues every product with commerce details, to rebuild the whole index.
     *
     * @return int how many
     */
    public function queueEverything(): int
    {
        $count = 0;
        $after = 0;
        $catalog = [];
        do {
            $ids = $this->products->ids($after, self::PAGE);
            $this->queue($ids);
            $count += \count($ids);
            array_push($catalog, ...$ids);
            $after = $ids === [] ? $after : $ids[\count($ids) - 1];
        } while (\count($ids) === self::PAGE);
        // Documents of products no longer in the catalog, whose removal the index missed, go now.
        $stale = array_values(array_diff($this->index->productIds(), $catalog));
        foreach (array_chunk($stale, self::PAGE) as $chunk) {
            $this->index->remove($chunk);
        }
        if ($count === 0) {
            $this->handler->markBuilt();
        }

        return $count;
    }

    public function status(): QueueStatus
    {
        $waiting = $this->jobs->waiting(IndexProducts::TYPE);
        $failed = [];
        foreach ($this->jobs->failed(IndexProducts::TYPE) as $job) {
            $failed[(int) $job->uniqueKey] = $job->error;
        }

        return new QueueStatus($waiting->due + $waiting->running, $waiting->later, $failed, $waiting->nextAt === null ? null : new \DateTimeImmutable($waiting->nextAt));
    }
}
