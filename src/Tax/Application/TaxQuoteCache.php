<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

/**
 * A tax service's answers, kept a few minutes so the cart, checkout and placing the order ask it once for the
 * same lines: services charge by the call, and each one is a wait at checkout. Rows of plain values only.
 */
interface TaxQuoteCache
{
    /**
     * @return list<array<string, string|bool|null>>|null null when nothing is kept
     */
    public function get(string $key): ?array;

    /**
     * @param list<array<string, string|bool|null>> $rows
     */
    public function put(string $key, array $rows, int $seconds): void;
}
