<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Events;

use OrillaCart\Catalog\Application\PriceHistory;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Domain\Event\ProductChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps a product's prices the moment it is saved: the lowest price of the 30 days before a sale counts from when
 * the price changed, not from when the search index got to the product (which also keeps them, for sales that
 * start and end by themselves).
 */
#[AsEventListener]
final readonly class RecordPriceChanges
{
    public function __construct(
        private ShopCatalog $catalog,
        private PriceHistory $history,
    ) {}

    public function __invoke(ProductChanged $event): void
    {
        $this->history->record(array_values($this->catalog->offers([$event->productId])));
    }
}
