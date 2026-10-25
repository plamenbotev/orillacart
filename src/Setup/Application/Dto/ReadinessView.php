<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

/**
 * Whether the shop is ready to sell: what stops it, then what is advised.
 */
final readonly class ReadinessView
{
    /**
     * @param list<Readiness> $stops
     * @param list<Readiness> $advice
     */
    public function __construct(
        public bool $ready,
        public array $stops,
        public array $advice,
    ) {}
}
