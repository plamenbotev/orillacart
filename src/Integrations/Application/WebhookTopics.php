<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application;

/** What webhooks can tell of: the shop's events, and those add-ons add (orillacart_webhook_topics). */
interface WebhookTopics
{
    public const string ORDER_PLACED = 'order.placed';
    public const string ORDER_PAID = 'order.paid';
    public const string ORDER_STATUS_CHANGED = 'order.status_changed';
    public const string ORDER_REFUNDED = 'order.refunded';
    public const string ORDER_DELETED = 'order.deleted';
    public const string PRODUCT_CHANGED = 'product.changed';
    public const string STOCK_CHANGED = 'stock.changed';
    /** The test the screen sends: to one webhook, whatever its topics. */
    public const string PING = 'webhook.ping';

    /** @return array<string, string> each topic's label, by topic */
    public function all(): array;
}
