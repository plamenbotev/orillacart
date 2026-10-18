<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\Events;

use OrillaCart\Notifications\Application\OrderEmails;
use OrillaCart\Ordering\Domain\Event\OrderNoteAdded;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class EmailAboutOrders
{
    public function __construct(private OrderEmails $emails) {}

    #[AsEventListener]
    public function statusChanged(OrderStatusChanged $event): void
    {
        $this->emails->statusChanged($event->orderId, $event->previous->value, $event->status->value, $event->tellCustomer, $event->tellShop);
    }

    #[AsEventListener]
    public function noteAdded(OrderNoteAdded $event): void
    {
        if ($event->forCustomer) {
            $this->emails->noteAdded($event->orderId, $event->text);
        }
    }

    #[AsEventListener]
    public function refunded(OrderRefunded $event): void
    {
        if ($event->tellCustomer) {
            $this->emails->refunded($event->orderId, $event->amount, $event->full, $event->quantities);
        }
    }
}
