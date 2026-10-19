<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Shipping;

use OrillaCart\Shipping\Application\RateCache;

final class MemoryRateCache implements RateCache
{
    /** @var array<string, list<array<string, string|int|bool|null>>> */
    public array $rows = [];
    /** @var array<string, int> how long each was kept for */
    public array $seconds = [];

    public function get(string $key): ?array
    {
        return $this->rows[$key] ?? null;
    }

    public function put(string $key, array $rows, int $seconds): void
    {
        $this->rows[$key] = $rows;
        $this->seconds[$key] = $seconds;
    }
}
