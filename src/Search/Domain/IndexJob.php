<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A product whose search document is to be built again, from when it is due. One row per product: changes that
 * arrive before a worker gets to it count up its version, so a burst of them is built once. The queue's SQL
 * (claiming with FOR UPDATE SKIP LOCKED) lives in the infrastructure; this mapping makes the migrations create it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'index_jobs')]
#[ORM\Index(name: 'index_job_due', columns: ['available_at'])]
class IndexJob
{
    /** After this many failed builds in a row the job is parked until the product changes again. */
    public const int MAX_ATTEMPTS = 5;

    #[ORM\Column]
    private int $version = 1;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastError = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $productId,
        #[ORM\Column]
        private \DateTimeImmutable $availableAt,
    ) {}

    public function productId(): int
    {
        return $this->productId;
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

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
