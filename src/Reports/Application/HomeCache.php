<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application;

/**
 * The Home screen's overview, kept a minute: its sales figures, counts and recent orders are a dozen queries over
 * the orders, which every visit to the admin's first page would run again (design 55).
 */
interface HomeCache
{
    /**
     * @return array<string, mixed>|null null when nothing is kept
     */
    public function get(string $key): ?array;

    /**
     * @param array<string, mixed> $overview
     */
    public function put(string $key, array $overview, int $seconds): void;
}
