<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\Events;

use OrillaCart\Catalog\Domain\Event\CatalogItemsRemoved;
use OrillaCart\Notifications\Domain\BackInStockRequests;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Nobody hears about a product the catalog deleted: the requests to hear when it is back go with it.
 */
#[AsEventListener]
final readonly class ForgetBackInStockOfRemovedProducts
{
    public function __construct(private BackInStockRequests $requests) {}

    public function __invoke(CatalogItemsRemoved $event): void
    {
        $this->requests->forgetProducts($event->productIds);
    }
}
