<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * A product a worker took from the queue, at the version it took: a change after that is built again.
 */
final readonly class ClaimedJob
{
    public function __construct(
        public int $productId,
        public int $version,
    ) {}
}
