<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\NexusThreshold;
use OrillaCart\Tax\Domain\NexusThresholds;

final readonly class NexusThresholdsView
{
    /**
     * @param array{sales: ?string, orders: ?int}                    $everywhere
     * @param list<array{state: string, sales: ?string, orders: ?int}> $states     by state code
     */
    public function __construct(
        public array $everywhere,
        public array $states,
    ) {}

    public static function fromThresholds(NexusThresholds $thresholds): self
    {
        $states = [];
        foreach ($thresholds->states as $state => $threshold) {
            $states[] = ['state' => (string) $state] + self::of($threshold);
        }
        usort($states, static fn(array $a, array $b): int => $a['state'] <=> $b['state']);

        return new self(self::of($thresholds->everywhere), $states);
    }

    /** @return array{sales: ?string, orders: ?int} */
    private static function of(NexusThreshold $threshold): array
    {
        return ['sales' => $threshold->sales, 'orders' => $threshold->orders];
    }
}
