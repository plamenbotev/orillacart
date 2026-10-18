<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\Events;

use OrillaCart\Catalog\Domain\Event\CatalogItemsRemoved;
use OrillaCart\Catalog\Domain\Event\PriceRulesChanged;
use OrillaCart\Catalog\Domain\Event\ProductChanged;
use OrillaCart\Inventory\Domain\Event\StockChanged;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Settings\Domain\SettingsChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Queues the products whose search documents the catalog's changes made stale.
 */
#[AsEventListener(event: ProductChanged::class, method: 'productChanged')]
#[AsEventListener(event: StockChanged::class, method: 'stockChanged')]
#[AsEventListener(event: CatalogItemsRemoved::class, method: 'itemsRemoved')]
#[AsEventListener(event: SettingsChanged::class, method: 'settingsChanged')]
#[AsEventListener(event: PriceRulesChanged::class, method: 'priceRulesChanged')]
final readonly class QueueChangedProducts
{
    public function __construct(private SearchIndexing $indexing) {}

    public function productChanged(ProductChanged $event): void
    {
        $this->indexing->queue([$event->productId]);
    }

    /** The index knows whether a variant is in stock, not how many: only running out or coming back counts. */
    public function stockChanged(StockChanged $event): void
    {
        if ($event->changedAvailability()) {
            $this->indexing->queueStockItem($event->item);
        }
    }

    /** Removed products leave the index; removed variations and options come with a ProductChanged. */
    public function itemsRemoved(CatalogItemsRemoved $event): void
    {
        $this->indexing->queue($event->productIds);
    }

    /** A price rule may change the guest's price of any product. */
    public function priceRulesChanged(PriceRulesChanged $event): void
    {
        $this->indexing->queueEverything();
    }

    /** Hiding or showing prices changes every product's price in the index. */
    public function settingsChanged(SettingsChanged $event): void
    {
        if ($event->previous->hidePrices !== $event->current->hidePrices) {
            $this->indexing->queueEverything();
        }
    }
}
