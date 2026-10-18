<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A job that used up its attempts, or that its handler gave up on: kept with its payload and error until the
 * shop retries or deletes it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'failed_jobs')]
#[ORM\Index(name: 'failed_job_when', columns: ['failed_at'])]
#[ORM\Index(name: 'failed_job_type', columns: ['type'])]
class FailedJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 40)]
        private string $queue,
        #[ORM\Column(length: Job::MAX_TYPE_LENGTH)]
        private string $type,
        #[ORM\Column(length: Job::MAX_KEY_LENGTH, nullable: true)]
        private ?string $uniqueKey,
        #[ORM\Column(type: 'text')]
        private string $payload,
        #[ORM\Column(type: 'text')]
        private string $error,
        #[ORM\Column]
        private int $attempts,
        #[ORM\Column]
        private \DateTimeImmutable $failedAt,
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

    public function error(): string
    {
        return $this->error;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function failedAt(): \DateTimeImmutable
    {
        return $this->failedAt;
    }
}
