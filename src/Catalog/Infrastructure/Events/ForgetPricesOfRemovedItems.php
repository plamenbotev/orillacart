<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Events;

use OrillaCart\Catalog\Domain\Event\CatalogItemsRemoved;
use OrillaCart\Catalog\Domain\PriceHistoryRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The price history of products and variations the catalog deleted goes with them.
 */
#[AsEventListener]
final readonly class ForgetPricesOfRemovedItems
{
    public function __construct(private PriceHistoryRepository $history) {}

    public function __invoke(CatalogItemsRemoved $event): void
    {
        $this->history->forget([
            ...array_map(static fn(int $id): string => "product:{$id}", $event->productIds),
            ...array_map(static fn(int $id): string => "variation:{$id}", $event->variationIds),
        ]);
    }
}
