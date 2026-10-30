<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * What one caller sent to one guard in one window of the rate limits, counted in a row of its own so that the
 * count is one atomic statement (INSERT … ON DUPLICATE KEY UPDATE) rather than a read and a write that two
 * requests at once can both make. The rows of windows that ended are swept as new ones begin.
 *
 * Only the mapping is used: the rate limiter writes the table with SQL the ORM cannot.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'rate_limits')]
#[ORM\Index(name: 'rate_limit_expires', columns: ['expires_at'])]
class RateLimitWindow
{
    public function __construct(
        /** The guard, the caller and the window, hashed. */
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        private string $bucket,
        #[ORM\Column]
        private int $hits,
        /** When the window ends. */
        #[ORM\Column]
        private \DateTimeImmutable $expiresAt,
    ) {}

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function hits(): int
    {
        return $this->hits;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
