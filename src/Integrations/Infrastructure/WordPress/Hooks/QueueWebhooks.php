<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress\Hooks;

use OrillaCart\Integrations\Application\Webhooks;
use OrillaCart\Integrations\Application\WebhookTopics;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Turns the shop's announced events (the orillacart_* actions, after their changes are saved) into webhook
 * deliveries, and add-ons' events sent with orillacart_send_webhook for the topics they added.
 */
#[AsHook('orillacart_order_placed', method: 'orderPlaced')]
#[AsHook('orillacart_order_paid', method: 'orderPaid')]
#[AsHook('orillacart_order_status_changed', method: 'orderStatusChanged', arguments: 3)]
#[AsHook('orillacart_order_refunded', method: 'orderRefunded', arguments: 3)]
#[AsHook('orillacart_order_deleted', method: 'orderDeleted')]
#[AsHook('orillacart_product_changed', method: 'productChanged')]
#[AsHook('orillacart_stock_changed', method: 'stockChanged', arguments: 3)]
#[AsHook('orillacart_send_webhook', method: 'added', arguments: 2)]
final readonly class QueueWebhooks
{
    public function __construct(
        private Webhooks $webhooks,
        private WebhookTopics $topics,
    ) {}

    public function orderPlaced(int $order): void
    {
        $this->webhooks->announce(WebhookTopics::ORDER_PLACED, ['order' => $order]);
    }

    public function orderPaid(int $order): void
    {
        $this->webhooks->announce(WebhookTopics::ORDER_PAID, ['order' => $order]);
    }

    public function orderStatusChanged(int $order, string $status, string $previous): void
    {
        $this->webhooks->announce(WebhookTopics::ORDER_STATUS_CHANGED, ['order' => $order, 'status' => $status, 'previous_status' => $previous]);
    }

    public function orderRefunded(int $order, mixed $amount, mixed $full): void
    {
        $this->webhooks->announce(WebhookTopics::ORDER_REFUNDED, ['order' => $order, 'amount' => \is_scalar($amount) ? (string) $amount : null, 'refunded_in_full' => (bool) $full]);
    }

    public function orderDeleted(int $order): void
    {
        $this->webhooks->announce(WebhookTopics::ORDER_DELETED, ['order' => $order]);
    }

    public function productChanged(int $product): void
    {
        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => $product]);
    }

    public function stockChanged(mixed $item, mixed $before, mixed $after): void
    {
        $this->webhooks->announce(WebhookTopics::STOCK_CHANGED, ['item' => \is_string($item) ? $item : null, 'before' => \is_int($before) ? $before : null, 'after' => \is_int($after) ? $after : null]);
    }

    /** An add-on's event, for a topic it added on orillacart_webhook_topics; the shop's own topics are its own. */
    public function added(mixed $topic, mixed $data): void
    {
        $core = [WebhookTopics::ORDER_PLACED, WebhookTopics::ORDER_PAID, WebhookTopics::ORDER_STATUS_CHANGED, WebhookTopics::ORDER_REFUNDED, WebhookTopics::ORDER_DELETED, WebhookTopics::PRODUCT_CHANGED, WebhookTopics::STOCK_CHANGED];
        if (\is_string($topic) && !\in_array($topic, $core, true) && isset($this->topics->all()[$topic]) && \is_array($data)) {
            $this->webhooks->announce($topic, array_filter($data, \is_string(...), ARRAY_FILTER_USE_KEY));
        }
    }
}
