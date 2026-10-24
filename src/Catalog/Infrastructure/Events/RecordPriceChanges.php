<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Events;

use OrillaCart\Catalog\Application\PriceHistory;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Domain\Event\ProductChanged;
use OrillaCart\Shared\Lifecycle\AfterResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps products' prices when they are saved: the lowest price of the 30 days before a sale counts from when the
 * price changed, not from when the search index got to the product (which also keeps them, for sales that start
 * and end by themselves). Every product a request changed is recorded together at its end, in one read of their
 * offers: an attribute set used by thousands of products is one save, not thousands. On the command line, where
 * nothing runs after the request, each as it changes.
 */
#[AsEventListener]
final class RecordPriceChanges
{
    /** @var array<int, true> */
    private array $changed = [];

    public function __construct(
        private readonly ShopCatalog $catalog,
        private readonly PriceHistory $history,
        private readonly ?AfterResponse $afterResponse = null,
    ) {}

    public function __invoke(ProductChanged $event): void
    {
        if ($this->afterResponse === null || \PHP_SAPI === 'cli') {
            $this->history->record(array_values($this->catalog->offers([$event->productId])));

            return;
        }
        $this->changed[$event->productId] = true;
        $this->afterResponse->defer('record-prices', $this->record(...));
    }

    public function record(): void
    {
        $ids = array_keys($this->changed);
        $this->changed = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $this->history->record(array_values($this->catalog->offers($chunk)));
        }
    }
}
