<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Work for later, in a lane (queue) that workers serve: build a product's search document, send an order's
 * email. A job with a unique key is the only pending one of its type and key: pushing it again counts up its
 * version, so a burst of changes is done once, and a change made while a worker runs it is done again. Workers
 * claim jobs with FOR UPDATE SKIP LOCKED; the SQL lives in the infrastructure, this mapping makes the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'jobs')]
#[ORM\UniqueConstraint(name: 'job_unique', columns: ['type', 'unique_key'])]
#[ORM\Index(name: 'job_due', columns: ['queue', 'available_at'])]
#[ORM\Index(name: 'job_available', columns: ['available_at'])]
#[ORM\Index(name: 'job_type_waiting', columns: ['type', 'available_at', 'locked_until'])]
class Job
{
    public const int MAX_TYPE_LENGTH = 80;
    public const int MAX_KEY_LENGTH = 150;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column]
    private int $version = 1;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lockedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    public function __construct(
        #[ORM\Column(length: 40)]
        private string $queue,
        #[ORM\Column(length: self::MAX_TYPE_LENGTH)]
        private string $type,
        #[ORM\Column(length: self::MAX_KEY_LENGTH, nullable: true)]
        private ?string $uniqueKey,
        #[ORM\Column(type: 'text')]
        private string $payload,
        #[ORM\Column]
        private \DateTimeImmutable $availableAt,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function queue(): string
    {
        return $this->queue;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function uniqueKey(): ?string
    {
        return $this->uniqueKey;
    }

    public function payload(): string
    {
        return $this->payload;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function availableAt(): \DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function lockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    public function lockedBy(): ?string
    {
        return $this->lockedBy;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
