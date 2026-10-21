<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * One try at telling a webhook of an event: what the address answered, or why it could not be reached.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_deliveries')]
#[ORM\Index(name: 'webhook_deliveries_of', columns: ['webhook_id', 'attempted_at'])]
class WebhookDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param string   $event  the event's id, the same on each try: the receiver tells repeats by it
     * @param int|null $status the HTTP status the address answered; null when it could not be reached
     */
    public function __construct(
        #[ORM\Column]
        private int $webhookId,
        #[ORM\Column(length: 36)]
        private string $event,
        #[ORM\Column(length: 100)]
        private string $topic,
        #[ORM\Column]
        private int $attempt,
        #[ORM\Column(nullable: true)]
        private ?int $status,
        #[ORM\Column(length: 255)]
        private string $error,
        #[ORM\Column]
        private int $milliseconds,
        #[ORM\Column]
        private \DateTimeImmutable $attemptedAt,
    ) {
        $this->error = mb_substr($error, 0, 255);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The delivery has no id until it is saved.');
    }

    public function webhookId(): int
    {
        return $this->webhookId;
    }

    public function event(): string
    {
        return $this->event;
    }

    public function topic(): string
    {
        return $this->topic;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    public function succeeded(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    public function error(): string
    {
        return $this->error;
    }

    public function milliseconds(): int
    {
        return $this->milliseconds;
    }

    public function attemptedAt(): \DateTimeImmutable
    {
        return $this->attemptedAt;
    }
}
