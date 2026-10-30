<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress\Hooks;

use OrillaCart\Integrations\Application\Webhooks;
use OrillaCart\Integrations\Application\WebhookTopics;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Turns add-ons' events, sent with orillacart_send_webhook for the topics they added, into webhook deliveries.
 * The shop's own events become deliveries from the domain events, inside their transaction (AnnounceToWebhooks);
 * the orillacart_* actions stay for add-ons to listen to.
 */
#[AsHook('orillacart_send_webhook', method: 'added', arguments: 2)]
final readonly class QueueWebhooks
{
    public function __construct(
        private Webhooks $webhooks,
        private WebhookTopics $topics,
    ) {}

    /** An add-on's event, for a topic it added on orillacart_webhook_topics; the shop's own topics are its own. */
    public function added(mixed $topic, mixed $data): void
    {
        $core = [WebhookTopics::ORDER_PLACED, WebhookTopics::ORDER_PAID, WebhookTopics::ORDER_STATUS_CHANGED, WebhookTopics::ORDER_REFUNDED, WebhookTopics::ORDER_DELETED, WebhookTopics::PRODUCT_CHANGED, WebhookTopics::STOCK_CHANGED];
        if (\is_string($topic) && !\in_array($topic, $core, true) && isset($this->topics->all()[$topic]) && \is_array($data)) {
            $this->webhooks->announce($topic, array_filter($data, \is_string(...), ARRAY_FILTER_USE_KEY));
        }
    }
}
