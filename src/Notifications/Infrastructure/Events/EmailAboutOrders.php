<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\Events;

use OrillaCart\Notifications\Application\OrderEmails;
use OrillaCart\Ordering\Domain\Event\OrderNeedsAttention;
use OrillaCart\Ordering\Domain\Event\OrderNoteAdded;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Shipping\Domain\Event\ConsignmentBooked;
use OrillaCart\Shipping\Domain\Event\OrderReadyForPickup;
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
            $this->emails->noteAdded($event->orderId, $event->noteId);
        }
    }

    #[AsEventListener]
    public function needsAttention(OrderNeedsAttention $event): void
    {
        $this->emails->needsAttention($event->orderId);
    }

    #[AsEventListener]
    public function consignmentBooked(ConsignmentBooked $event): void
    {
        $this->emails->trackingAdded($event->orderId);
    }

    #[AsEventListener]
    public function readyForPickup(OrderReadyForPickup $event): void
    {
        $this->emails->readyForPickup($event->orderId);
    }

    #[AsEventListener]
    public function refunded(OrderRefunded $event): void
    {
        $this->emails->refunded($event->orderId, $event->amount, $event->full, $event->quantities, $event->tellCustomer);
    }
}
