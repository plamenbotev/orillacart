<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Infrastructure\Events;

use OrillaCart\Catalog\Domain\Event\CatalogItemsRemoved;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Stock of products, variations and options the catalog deleted is no longer counted.
 */
#[AsEventListener]
final readonly class ForgetStockOfRemovedItems
{
    public function __construct(private StockService $stock) {}

    public function __invoke(CatalogItemsRemoved $event): void
    {
        $this->stock->removeItems([
            ...array_map(StockItemRef::product(...), $event->productIds),
            ...array_map(StockItemRef::variation(...), $event->variationIds),
            ...array_map(StockItemRef::option(...), $event->optionIds),
        ]);
    }
}
