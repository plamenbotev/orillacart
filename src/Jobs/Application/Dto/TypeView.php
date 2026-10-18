<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * A job type as it is now.
 */
final readonly class TypeView
{
    public function __construct(
        public string $type,
        public string $queue,
        public int $due,
        public int $running,
        public int $later,
        public int $failed,
    ) {}
}
