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

    /**
     * The index knows whether a variant is in stock, not how many: only running out or coming back counts. Below
     * zero, on backorder, any change may use up or free the backorder limit, which only the catalog knows.
     */
    public function stockChanged(StockChanged $event): void
    {
        if ($event->changedAvailability() || ($event->before ?? 0) < 0 || ($event->after ?? 0) < 0) {
            $this->indexing->queueStockItem($event->item);
        }
    }

    /** Removed products leave the index; removed variations and options come with a ProductChanged. */
    public function itemsRemoved(CatalogItemsRemoved $event): void
    {
        $this->indexing->queue($event->productIds);
    }

    /**
     * The index keeps guests' prices: a rule only for customer groups changes none of them, and a rule for some
     * products, categories or brands changes only theirs. A rule for the whole catalog queues everything.
     */
    public function priceRulesChanged(PriceRulesChanged $event): void
    {
        $products = [];
        $categories = [];
        $brands = [];
        foreach ($event->scopes as $scope) {
            if ($scope['groups'] !== []) {
                continue;
            }
            if ($scope['products'] === [] && $scope['categories'] === [] && $scope['brands'] === []) {
                $this->indexing->queueEverything();

                return;
            }
            array_push($products, ...$scope['products']);
            array_push($categories, ...$scope['categories']);
            array_push($brands, ...$scope['brands']);
        }
        $this->indexing->queuePricedFor($products, array_values(array_unique($categories)), array_values(array_unique($brands)));
    }

    /** Hiding or showing prices changes every product's price in the index. */
    public function settingsChanged(SettingsChanged $event): void
    {
        if ($event->previous->hidePrices !== $event->current->hidePrices) {
            $this->indexing->queueEverything();
        }
    }
}
