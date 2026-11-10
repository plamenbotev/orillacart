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
use OrillaCart\Shared\Users\ShopManagers;
use OrillaCart\Shared\Users\UserNames;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Webhooks: addresses the shop tells of its events. Each event a webhook listens to becomes a job
 * (DeliverWebhook), so a slow or broken receiver never holds up the shop; it is tried again when it fails. Each
 * is in the name of the shop manager who saved it last, and stops when they no longer manage the shop.
 */
final readonly class Webhooks
{
    public function __construct(
        private WebhookRepository $webhooks,
        private WebhookTopics $topics,
        private Jobs $jobs,
        private ClockInterface $clock,
        private UserNames $users,
        private ShopManagers $managers,
        private LoggerInterface $logger,
    ) {}

    /** @return list<WebhookView> by name */
    public function all(): array
    {
        $webhooks = $this->webhooks->all();
        $names = $this->users->names(array_values(array_unique(array_filter(array_map(static fn(Webhook $webhook): ?int => $webhook->ownerId(), $webhooks), \is_int(...)))));

        return array_map(fn(Webhook $webhook): WebhookView => $this->view($webhook, names: $names), $webhooks);
    }

    /** @return array<string, string> what webhooks can tell of, by topic */
    public function topics(): array
    {
        return $this->topics->all();
    }

    /**
     * @throws IntegrationRuleViolation
     */
    public function create(SaveWebhook $command, ?int $by): WebhookView
    {
        $this->knownTopics($command->topics);
        $webhook = new Webhook($command->name, $command->url, $command->topics, $command->active, self::secret(), $this->clock->now(), $by, $command->failureLimit);
        $this->webhooks->save($webhook);

        return $this->view($webhook, withSecret: true);
    }

    /**
     * @throws IntegrationRuleViolation
     */
    public function update(int $id, SaveWebhook $command, ?int $by): WebhookView
    {
        $webhook = $this->get($id);
        $this->knownTopics($command->topics);
        $webhook->change($command->name, $command->url, $command->topics, $command->active, $by, $command->failureLimit);
        $this->webhooks->save($webhook);

        return $this->view($webhook);
    }

    /** A new secret: the receiver checks signatures with it from now on. It is shown this once. */
    public function newSecret(int $id): WebhookView
    {
        $webhook = $this->get($id);
        $webhook->newSecret(self::secret());
        $this->webhooks->save($webhook);

        return $this->view($webhook, withSecret: true);
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
     * The user's account was deleted, their role changed, or they were taken off the site: the webhooks in their
     * name are switched off while they no longer manage the shop, and the shop is told.
     *
     * @param bool $leaving they are being taken off the site, and still have its roles while that is told
     *
     * @return int how many were switched off
     */
    public function ownerChanged(int $userId, bool $leaving = false): int
    {
        if (!$leaving && $this->managers->manages($userId)) {
            return 0;
        }
        $off = 0;
        foreach ($this->webhooks->all() as $webhook) {
            if ($webhook->ownerId() === $userId && $this->switchOff($webhook)) {
                ++$off;
            }
        }

        return $off;
    }

    /** @return bool whether it was switched off now */
    private function switchOff(Webhook $webhook): bool
    {
        if (!$webhook->ownerLeft($this->clock->now())) {
            return false;
        }
        $this->webhooks->save($webhook);
        $this->logger->warning('Webhook "{name}" (#{id}) was switched off: the shop manager it was saved by no longer manages the shop. A manager who switches it on again answers for it.', ['name' => $webhook->name(), 'id' => $webhook->id()]);

        return true;
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

    /**
     * @param array<int, string>|null $names the owners' names, when they were read for a list
     */
    private function view(Webhook $webhook, bool $withSecret = false, ?array $names = null): WebhookView
    {
        $last = $this->webhooks->deliveries($webhook->id(), 1)[0] ?? null;
        $owner = $webhook->ownerId();
        $names ??= $owner === null ? [] : $this->users->names([$owner]);

        return WebhookView::of($webhook, $last === null ? null : WebhookDeliveryView::of($last), $withSecret, $owner === null ? '' : ($names[$owner] ?? ''));
    }

    /**
     * @param list<string> $topics
     */
    private function knownTopics(array $topics): void
    {
        $unknown = array_diff($topics, array_keys($this->topics->all()));
        if ($unknown !== []) {
            throw new IntegrationRuleViolation('The shop has no webhook topic %s.', 'orillacart_invalid_webhook_topics', values: [implode(', ', $unknown)]);
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
