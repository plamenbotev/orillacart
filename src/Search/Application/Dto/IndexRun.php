<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * What one pass over the queue did.
 */
final readonly class IndexRun
{
    public function __construct(
        public int $built = 0,
        public int $removed = 0,
        public int $failed = 0,
    ) {}

    public function count(): int
    {
        return $this->built + $this->removed + $this->failed;
    }
}
