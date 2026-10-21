<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

interface WebhookRepository
{
    /** @return list<Webhook> by name */
    public function all(): array;

    public function find(int $id): ?Webhook;

    public function save(Webhook $webhook): void;

    /** Removes it with its deliveries. */
    public function remove(Webhook $webhook): void;

    /** Keeps the delivery, and only the webhook's latest KEEP after it. */
    public function record(WebhookDelivery $delivery): void;

    /**
     * @return list<WebhookDelivery> newest first
     */
    public function deliveries(int $webhookId, int $limit): array;
}
