<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A long-running worker as it last told: which lanes it serves, what it is on, how much it did and how much
 * memory it uses. One row per worker, so many of them never write the same row.
 */
#[ORM\Entity]
#[ORM\Table(name: 'job_workers')]
#[ORM\Index(name: 'job_worker_seen', columns: ['seen_at'])]
class JobWorker
{
    #[ORM\Column]
    private \DateTimeImmutable $seenAt;

    #[ORM\Column(length: Job::MAX_TYPE_LENGTH, nullable: true)]
    private ?string $working = null;

    #[ORM\Column]
    private int $jobsDone = 0;

    #[ORM\Column(type: 'bigint')]
    private int $memory = 0;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        private string $name,
        #[ORM\Column(length: 255)]
        private string $queues,
        #[ORM\Column]
        private \DateTimeImmutable $startedAt,
    ) {
        $this->seenAt = $startedAt;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function queues(): string
    {
        return $this->queues;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function seenAt(): \DateTimeImmutable
    {
        return $this->seenAt;
    }

    public function working(): ?string
    {
        return $this->working;
    }

    public function jobsDone(): int
    {
        return $this->jobsDone;
    }

    public function memory(): int
    {
        return $this->memory;
    }
}
