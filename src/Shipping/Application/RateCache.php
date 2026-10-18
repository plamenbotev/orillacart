<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

/**
 * Keeps what a shipping method quoted for a few minutes, so checkout repricing the same parcel asks the courier
 * once. Rates are kept as plain rows.
 */
interface RateCache
{
    /**
     * @return list<array{code: string, name: string, price: string, days: ?int}>|null null when nothing is kept
     */
    public function get(string $key): ?array;

    /**
     * @param list<array{code: string, name: string, price: string, days: ?int}> $rates
     */
    public function put(string $key, array $rates, int $seconds): void;
}
