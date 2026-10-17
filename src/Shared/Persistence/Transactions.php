<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Persistence;

/**
 * Runs work that changes several aggregates, possibly of several areas, as one database transaction:
 * all of it is saved, or none of it when the work throws.
 */
interface Transactions
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed;
}
