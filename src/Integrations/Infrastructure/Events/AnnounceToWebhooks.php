<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\Events;

use OrillaCart\Catalog\Domain\Event\ProductChanged;
use OrillaCart\Integrations\Application\Webhooks;
use OrillaCart\Integrations\Application\WebhookTopics;
use OrillaCart\Inventory\Domain\Event\StockChanged;
use OrillaCart\Ordering\Domain\Event\OrderDeleted;
use OrillaCart\Ordering\Domain\Event\OrderPaid;
use OrillaCart\Ordering\Domain\Event\OrderPlaced;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Turns the shop's events into webhook deliveries, from the domain events themselves, inside the transaction
 * they happen in: the delivery job is part of the change, so a process killed between the commit and the push
 * cannot lose it, and a change rolled back announces nothing. The data a webhook carries is what the event
 * announces to WordPress too (its hook arguments), so add-ons and webhooks see one and the same event.
 */
final readonly class AnnounceToWebhooks
{
    public function __construct(private Webhooks $webhooks) {}

    #[AsEventListener]
    public function orderPlaced(OrderPlaced $event): void
    {
        $this->webhooks->announce(WebhookTopics::ORDER_PLACED, ['order' => $event->orderId]);
    }

    #[AsEventListener]
    public function orderPaid(OrderPaid $event): void
    {
        $this->webhooks->announce(WebhookTopics::ORDER_PAID, ['order' => $event->orderId]);
    }

    #[AsEventListener]
    public function orderStatusChanged(OrderStatusChanged $event): void
    {
        $this->webhooks->announce(WebhookTopics::ORDER_STATUS_CHANGED, ['order' => $event->orderId, 'status' => $event->status->value, 'previous_status' => $event->previous->value]);
    }

    #[AsEventListener]
    public function orderRefunded(OrderRefunded $event): void
    {
        $this->webhooks->announce(WebhookTopics::ORDER_REFUNDED, ['order' => $event->orderId, 'amount' => $event->amount, 'refunded_in_full' => $event->full]);
    }

    #[AsEventListener]
    public function orderDeleted(OrderDeleted $event): void
    {
        $this->webhooks->announce(WebhookTopics::ORDER_DELETED, ['order' => $event->orderId]);
    }

    #[AsEventListener]
    public function productChanged(ProductChanged $event): void
    {
        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => $event->productId]);
    }

    #[AsEventListener]
    public function stockChanged(StockChanged $event): void
    {
        $this->webhooks->announce(WebhookTopics::STOCK_CHANGED, ['item' => $event->item, 'before' => $event->before, 'after' => $event->after]);
    }
}
