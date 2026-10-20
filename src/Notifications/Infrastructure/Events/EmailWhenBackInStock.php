<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\Events;

use OrillaCart\Customers\Domain\Event\PersonErased;
use OrillaCart\Inventory\Domain\Event\StockChanged;
use OrillaCart\Notifications\Application\BackInStock;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class EmailWhenBackInStock
{
    public function __construct(private BackInStock $backInStock) {}

    #[AsEventListener]
    public function stockChanged(StockChanged $event): void
    {
        $this->backInStock->stockChanged($event->item, $event->before, $event->after);
    }

    #[AsEventListener]
    public function erased(PersonErased $event): void
    {
        $this->backInStock->forgetEmail($event->email);
    }
}
