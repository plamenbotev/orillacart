<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * How far behind the search index is.
 */
final readonly class QueueStatus
{
    /**
     * @param int                       $due     products to build now
     * @param int                       $later   products to build when a sale starts or ends
     * @param array<int, string>        $parked  products whose build failed too often, with the error, by product id
     */
    public function __construct(
        public int $due,
        public int $later,
        public array $parked,
        public ?\DateTimeImmutable $nextAt,
    ) {}
}
