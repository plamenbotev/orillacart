<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

/**
 * Keeps what a shipping method answered (rates, pickup points) for a while, so checkout repricing the same parcel
 * asks the courier once. Answers are kept as plain rows.
 */
interface RateCache
{
    /**
     * @return list<array<string, string|int|bool|null>>|null null when nothing is kept
     */
    public function get(string $key): ?array;

    /**
     * @param list<array<string, string|int|bool|null>> $rows
     */
    public function put(string $key, array $rows, int $seconds): void;
}
