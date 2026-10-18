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
     * @param-immediately-invoked-callable $work
     *
     * @return T
     */
    public function run(callable $work): mixed;

    /**
     * Like run(), and when the database gives up on the outermost transaction because other work holds the same
     * rows (a deadlock, a lock wait that timed out), runs the work once more. The work must build what it saves
     * anew each time, from ids and values, never from entities it was handed.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @param-immediately-invoked-callable $work
     *
     * @return T
     *
     * @throws Contention when the second try fails the same way
     */
    public function runRetrying(callable $work): mixed;
}
