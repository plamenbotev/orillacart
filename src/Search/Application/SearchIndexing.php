<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Search\Application\Dto\QueueStatus;
use OrillaCart\Shared\Lifecycle\AfterResponse;
use Psr\Clock\ClockInterface;

/**
 * Keeps the search index in step with the catalog: queues the products that changed, or all of them.
 */
final readonly class SearchIndexing
{
    private const int PAGE = 500;
    /** How many documents a request builds after its response, so the shop shows its changes at once. */
    private const int AFTER_RESPONSE = 10;

    public function __construct(
        private IndexQueue $queue,
        private ProductLookup $products,
        private ClockInterface $clock,
        private AfterResponse $afterResponse,
        private IndexWorker $worker,
    ) {}

    /**
     * @param list<int> $productIds
     */
    public function queue(array $productIds): void
    {
        if ($productIds !== []) {
            $this->queue->push(array_values(array_unique($productIds)), $this->clock->now());
            $this->afterResponse->defer(self::class, fn() => $this->worker->work(self::AFTER_RESPONSE));
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
        do {
            $ids = $this->products->ids($after, self::PAGE);
            $this->queue($ids);
            $count += \count($ids);
            $after = $ids === [] ? $after : $ids[\count($ids) - 1];
        } while (\count($ids) === self::PAGE);

        return $count;
    }

    public function status(): QueueStatus
    {
        return $this->queue->status($this->clock->now());
    }
}
