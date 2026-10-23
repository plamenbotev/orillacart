<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Tax;

use OrillaCart\Tax\Application\TaxQuoteCache;

final class MemoryTaxQuoteCache implements TaxQuoteCache
{
    /** @var array<string, list<array<string, string|bool|null>>> */
    public array $rows = [];

    public function get(string $key): ?array
    {
        return $this->rows[$key] ?? null;
    }

    public function put(string $key, array $rows, int $seconds): void
    {
        $this->rows[$key] = $rows;
    }
}
