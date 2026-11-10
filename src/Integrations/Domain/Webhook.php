<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * An address the shop tells of what happens (an order placed, a product changed): each event of its topics is
 * POSTed there as JSON, signed with its secret. A shop manager answers for it, who made it or last saved it: when
 * they no longer manage the shop (their role was changed, their account deleted) it is switched off, as it is
 * after a run of events it never took, until a manager switches it on again and answers for it from then on.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhooks')]
class Webhook
{
    public const int MAX_NAME_LENGTH = 100;
    /** How many events in a row a webhook may fail for good before it is switched off, unless it is told otherwise. */
    public const int DEFAULT_FAILURE_LIMIT = 10;
    public const int MAX_FAILURE_LIMIT = 1000;
    /** Why the shop switched it off: the manager who answers for it does not manage the shop any more. */
    public const string OFF_OWNER_LEFT = 'owner_left';
    /** Why the shop switched it off: its address took none of the last events, each tried as often as it is. */
    public const string OFF_FAILING = 'failing';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 500)]
    private string $url;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $topics;

    #[ORM\Column]
    private bool $active = false;

    /** The shop manager who answers for it: who made it, or saved it last. */
    #[ORM\Column(nullable: true)]
    private ?int $ownerId = null;

    /** How many events in a row it failed for good; 0 once one is taken. */
    #[ORM\Column(name: 'failed_in_a_row', options: ['default' => 0])]
    private int $failedInARow = 0;

    /** How many events in a row it may fail for good before the shop switches it off; 0 for never. */
    #[ORM\Column(options: ['default' => self::DEFAULT_FAILURE_LIMIT])]
    private int $failureLimit = self::DEFAULT_FAILURE_LIMIT;

    /** Why the shop switched it off (OFF_*), and when; null when a manager did, or it is on. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $offReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $offAt = null;

    /**
     * @param list<string> $topics
     *
     * @throws IntegrationRuleViolation
     */
    public function __construct(
        string $name,
        string $url,
        array $topics,
        bool $active,
        #[\SensitiveParameter]
        #[ORM\Column(type: 'sealed_string', length: 255)]
        private string $secret,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        ?int $ownerId = null,
        int $failureLimit = self::DEFAULT_FAILURE_LIMIT,
    ) {
        $this->change($name, $url, $topics, $active, $ownerId, $failureLimit);
    }

    /**
     * Saved by a shop manager, who answers for it from now on. Switched on, it starts its count of failed events
     * again.
     *
     * @param list<string> $topics
     *
     * @throws IntegrationRuleViolation for no name, an address that is not https (but on this computer), no topics,
     *                                  or a failure limit out of range
     */
    public function change(string $name, string $url, array $topics, bool $active, ?int $by = null, int $failureLimit = self::DEFAULT_FAILURE_LIMIT): void
    {
        if ($failureLimit < 0 || $failureLimit > self::MAX_FAILURE_LIMIT) {
            throw new IntegrationRuleViolation('Switch the webhook off after 1 to %d failed events in a row, or 0 for never.', 'orillacart_invalid_webhook_failure_limit', values: [self::MAX_FAILURE_LIMIT]);
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new IntegrationRuleViolation('Name the webhook in 1 to %d characters.', 'orillacart_invalid_webhook', values: [self::MAX_NAME_LENGTH]);
        }
        $parts = parse_url(trim($url));
        $host = \is_array($parts) ? ($parts['host'] ?? '') : '';
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $local = \in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost');
        if ($host === '' || mb_strlen($url) > 500 || !($scheme === 'https' || ($scheme === 'http' && $local))) {
            throw new IntegrationRuleViolation('A webhook goes to an https:// address (http:// only on this computer).', 'orillacart_invalid_webhook_url');
        }
        if ($topics === []) {
            throw new IntegrationRuleViolation('Choose what the webhook tells of.', 'orillacart_invalid_webhook_topics');
        }
        $this->name = $name;
        $this->url = trim($url);
        $this->topics = array_values(array_unique($topics));
        if ($active && !$this->active) {
            $this->failedInARow = 0;
        }
        // Left off, a webhook the shop switched off keeps saying why.
        if ($active || $this->active) {
            $this->offReason = null;
            $this->offAt = null;
        }
        $this->active = $active;
        $this->failureLimit = $failureLimit;
        if ($by !== null) {
            $this->ownerId = $by;
        }
    }

    /**
     * The manager who answers for it does not manage the shop any more: it is switched off, and says why.
     *
     * @return bool false when it was off already
     */
    public function ownerLeft(\DateTimeImmutable $at): bool
    {
        return $this->switchOff(self::OFF_OWNER_LEFT, $at);
    }

    /** An event it took: the count of failed ones starts again. */
    public function delivered(): void
    {
        $this->failedInARow = 0;
    }

    /**
     * An event it failed for good, after every try: one more in the row, and at the limit it is switched off.
     *
     * @return bool whether this switched it off
     */
    public function eventFailed(\DateTimeImmutable $at): bool
    {
        ++$this->failedInARow;

        return $this->failureLimit > 0 && $this->failedInARow >= $this->failureLimit && $this->switchOff(self::OFF_FAILING, $at);
    }

    public function ownerId(): ?int
    {
        return $this->ownerId;
    }

    public function failedInARow(): int
    {
        return $this->failedInARow;
    }

    public function failureLimit(): int
    {
        return $this->failureLimit;
    }

    public function offReason(): ?string
    {
        return $this->offReason;
    }

    public function offAt(): ?\DateTimeImmutable
    {
        return $this->offAt;
    }

    private function switchOff(string $reason, \DateTimeImmutable $at): bool
    {
        if (!$this->active) {
            return false;
        }
        $this->active = false;
        $this->offReason = $reason;
        $this->offAt = $at;

        return true;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The webhook has no id until it is saved.');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function url(): string
    {
        return $this->url;
    }

    /** @return list<string> */
    public function topics(): array
    {
        return $this->topics;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function listensTo(string $topic): bool
    {
        return $this->active && \in_array($topic, $this->topics, true);
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function newSecret(#[\SensitiveParameter] string $secret): void
    {
        $this->secret = $secret;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
