<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application;

use OrillaCart\Integrations\Domain\Webhook;
use OrillaCart\Integrations\Domain\WebhookDelivery;
use OrillaCart\Integrations\Domain\WebhookRepository;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Shared\Users\ShopManagers;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * POSTs an event to a webhook: {"id", "topic", "created_at", "data"} as JSON, with the event's id and topic in
 * X-OrillaCart-Event and X-OrillaCart-Topic, and X-OrillaCart-Signature "t=<unix time>,v1=<hex>": the HMAC-SHA256,
 * with the webhook's secret, of "<time>.<body>", so the receiver knows the shop sent it, and when. An answer
 * other than 2xx is tried again later; each try is kept for the screen. An event every try of which failed counts
 * against the webhook, which the shop switches off at its limit of such events in a row, and tells the shop of. A
 * webhook goes on only while the shop manager it was saved by manages the shop. Each delivery is reported as it is
 * done, and the lock covers one of them, extended as the batch goes on.
 */
final readonly class DeliverWebhook implements JobHandler
{
    public const string TYPE = 'webhook.deliver';

    public function __construct(
        private WebhookRepository $webhooks,
        private WebhookPayloads $payloads,
        private WebhookSender $sender,
        private ClockInterface $clock,
        private ShopManagers $managers,
        private LoggerInterface $logger,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return 'webhooks';
    }

    public function maxAttempts(): int
    {
        return 6;
    }

    public function retryAfter(int $attempt): int
    {
        // 1, 4, 16 and 64 minutes, then about 4 hours.
        return 60 * 4 ** ($attempt - 1);
    }

    public function lockSeconds(): int
    {
        // One delivery: the receiver gets ten seconds to answer (WordPressWebhookSender), the rest is slack.
        return 30;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        foreach ($jobs as $job) {
            $progress->done($job, $this->deliver($job));
        }

        return [];
    }

    /** One delivery. */
    private function deliver(QueuedJob $job): JobOutcome
    {
        $webhook = is_numeric($job->payload['webhook'] ?? null) ? $this->webhooks->find((int) $job->payload['webhook']) : null;
        $topic = \is_string($job->payload['topic'] ?? null) ? $job->payload['topic'] : '';
        $event = \is_string($job->payload['event'] ?? null) ? $job->payload['event'] : '';
        // A webhook deleted, or turned off since, hears no more; a test goes whatever its state.
        if ($webhook === null || (!$webhook->isActive() && $topic !== WebhookTopics::PING)) {
            return JobOutcome::done();
        }
        // However the manager stopped managing the shop (a role changed by hand, a capability a plugin took away),
        // what was set up in their name stops with them: checked as each event goes.
        $owner = $webhook->ownerId();
        if ($topic !== WebhookTopics::PING && ($owner === null || !$this->managers->manages($owner))) {
            if ($webhook->ownerLeft($this->clock->now())) {
                $this->webhooks->save($webhook);
                $this->logger->warning('Webhook "{name}" (#{id}) was switched off: the shop manager it was saved by no longer manages the shop. A manager who switches it on again answers for it.', ['name' => $webhook->name(), 'id' => $webhook->id()]);
            }

            return JobOutcome::done();
        }
        if ($webhook->secret() === '') {
            // Sealed with salts the site no longer has: nothing unsigned goes out.
            return JobOutcome::fail(\sprintf('Webhook #%d\'s secret cannot be read since the site\'s salts changed: make a new secret.', $webhook->id()));
        }
        $body = (string) json_encode([
            'id' => $event,
            'topic' => $topic,
            'created_at' => \is_string($job->payload['at'] ?? null) ? $job->payload['at'] : $this->clock->now()->format(\DATE_ATOM),
            'data' => $this->payloads->data($topic, \is_array($job->payload['arguments'] ?? null) ? $job->payload['arguments'] : []),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $time = (string) $this->clock->now()->getTimestamp();
        $response = $this->sender->post($webhook->url(), $body, [
            'Content-Type' => 'application/json',
            'X-OrillaCart-Event' => $event,
            'X-OrillaCart-Topic' => $topic,
            'X-OrillaCart-Attempt' => (string) $job->attempts,
            'X-OrillaCart-Signature' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, $webhook->secret()),
        ]);
        $this->webhooks->record(new WebhookDelivery($webhook->id(), $event, $topic, $job->attempts, $response->status, $response->error, $response->milliseconds, $this->clock->now()));
        if (!$response->succeeded()) {
            // A test is no event: it does not count for or against the webhook.
            if ($topic !== WebhookTopics::PING && $job->attempts >= $this->maxAttempts()) {
                $this->failedForGood($webhook);
            }

            return JobOutcome::retry(\sprintf('%s answered %s', $webhook->url(), $response->status ?? $response->error));
        }
        if ($topic !== WebhookTopics::PING && $webhook->failedInARow() > 0) {
            $webhook->delivered();
            $this->webhooks->save($webhook);
        }

        return JobOutcome::done();
    }

    /** The event's last try failed: one more in the webhook's row, which may switch it off. */
    private function failedForGood(Webhook $webhook): void
    {
        $off = $webhook->eventFailed($this->clock->now());
        $this->webhooks->save($webhook);
        if ($off) {
            $this->logger->error('Webhook "{name}" (#{id}) was switched off: {url} took none of the last {count} events, each tried {tries} times. Switch it on again once the address takes them.', ['name' => $webhook->name(), 'id' => $webhook->id(), 'url' => $webhook->url(), 'count' => $webhook->failedInARow(), 'tries' => $this->maxAttempts()]);
        }
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Tell webhook #%s of %s', is_numeric($payload['webhook'] ?? null) ? (string) $payload['webhook'] : '?', \is_string($payload['topic'] ?? null) ? $payload['topic'] : '?');
    }
}
