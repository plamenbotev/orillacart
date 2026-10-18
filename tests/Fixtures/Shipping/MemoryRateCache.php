<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Shipping;

use OrillaCart\Shipping\Application\RateCache;

final class MemoryRateCache implements RateCache
{
    /** @var array<string, list<array{code: string, name: string, price: string, days: ?int}>> */
    public array $rates = [];

    public function get(string $key): ?array
    {
        return $this->rates[$key] ?? null;
    }

    public function put(string $key, array $rates, int $seconds): void
    {
        $this->rates[$key] = $rates;
    }
}
