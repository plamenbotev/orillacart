<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application;

use OrillaCart\Integrations\Application\Dto\SaveWebhook;
use OrillaCart\Integrations\Application\Dto\WebhookDeliveryView;
use OrillaCart\Integrations\Application\Dto\WebhookView;
use OrillaCart\Integrations\Domain\IntegrationRuleViolation;
use OrillaCart\Integrations\Domain\Webhook;
use OrillaCart\Integrations\Domain\WebhookRepository;
use OrillaCart\Jobs\Application\Jobs;
use Psr\Clock\ClockInterface;

/**
 * Webhooks: addresses the shop tells of its events. Each event a webhook listens to becomes a job
 * (DeliverWebhook), so a slow or broken receiver never holds up the shop; it is tried again when it fails.
 */
final readonly class Webhooks
{
    public function __construct(
        private WebhookRepository $webhooks,
        private WebhookTopics $topics,
        private Jobs $jobs,
        private ClockInterface $clock,
    ) {}

    /** @return list<WebhookView> by name */
    public function all(): array
    {
        return array_map(fn(Webhook $webhook): WebhookView => $this->view($webhook), $this->webhooks->all());
    }

    /** @return array<string, string> what webhooks can tell of, by topic */
    public function topics(): array
    {
        return $this->topics->all();
    }

    /**
     * @throws IntegrationRuleViolation
     */
    public function create(SaveWebhook $command): WebhookView
    {
        $this->knownTopics($command->topics);
        $webhook = new Webhook($command->name, $command->url, $command->topics, $command->active, self::secret(), $this->clock->now());
        $this->webhooks->save($webhook);

        return $this->view($webhook);
    }

    /**
     * @throws IntegrationRuleViolation
     */
    public function update(int $id, SaveWebhook $command): WebhookView
    {
        $webhook = $this->get($id);
        $this->knownTopics($command->topics);
        $webhook->change($command->name, $command->url, $command->topics, $command->active);
        $this->webhooks->save($webhook);

        return $this->view($webhook);
    }

    /** A new secret: the receiver checks signatures with it from now on. */
    public function newSecret(int $id): WebhookView
    {
        $webhook = $this->get($id);
        $webhook->newSecret(self::secret());
        $this->webhooks->save($webhook);

        return $this->view($webhook);
    }

    public function delete(int $id): void
    {
        $this->webhooks->remove($this->get($id));
    }

    /** Sends the webhook a test (webhook.ping), whatever its topics. */
    public function ping(int $id): void
    {
        $webhook = $this->get($id);
        $this->queue($webhook, WebhookTopics::PING, ['message' => 'A test from the shop.']);
    }

    /** @return list<WebhookDeliveryView> newest first */
    public function deliveries(int $id, int $limit = 20): array
    {
        return array_map(WebhookDeliveryView::of(...), $this->webhooks->deliveries($this->get($id)->id(), $limit));
    }

    /**
     * Tells every webhook that listens to the topic: the event's arguments become its data at delivery.
     *
     * @param array<string, mixed> $arguments
     */
    public function announce(string $topic, array $arguments): void
    {
        foreach ($this->webhooks->all() as $webhook) {
            if ($webhook->listensTo($topic)) {
                $this->queue($webhook, $topic, $arguments);
            }
        }
    }

    public function webhook(int $id): ?Webhook
    {
        return $this->webhooks->find($id);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function queue(Webhook $webhook, string $topic, array $arguments): void
    {
        $this->jobs->push(DeliverWebhook::TYPE, [
            'webhook' => $webhook->id(),
            'event' => self::uuid(),
            'topic' => $topic,
            'arguments' => $arguments,
            'at' => $this->clock->now()->format(\DATE_ATOM),
        ]);
    }

    private function get(int $id): Webhook
    {
        return $this->webhooks->find($id) ?? throw new IntegrationRuleViolation('There is no such webhook.', 'orillacart_unknown_webhook');
    }

    private function view(Webhook $webhook): WebhookView
    {
        $last = $this->webhooks->deliveries($webhook->id(), 1)[0] ?? null;

        return WebhookView::of($webhook, $last === null ? null : WebhookDeliveryView::of($last));
    }

    /**
     * @param list<string> $topics
     */
    private function knownTopics(array $topics): void
    {
        $unknown = array_diff($topics, array_keys($this->topics->all()));
        if ($unknown !== []) {
            throw new IntegrationRuleViolation(\sprintf('The shop has no webhook topic %s.', implode(', ', $unknown)), 'orillacart_invalid_webhook_topics');
        }
    }

    private static function secret(): string
    {
        return bin2hex(random_bytes(24));
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
