<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * What one pass over the queue did.
 */
final readonly class JobRun
{
    public function __construct(
        public int $done = 0,
        public int $retried = 0,
        public int $failed = 0,
    ) {}

    public function count(): int
    {
        return $this->done + $this->retried + $this->failed;
    }
}
