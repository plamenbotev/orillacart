<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures;

use OrillaCart\Shared\Persistence\Transactions;

/**
 * Runs the work as it is: in-memory repositories have nothing to roll back. It keeps count of how deep the work
 * runs, so in-memory repositories can refuse a lock outside a transaction as Doctrine does.
 */
final class ImmediateTransactions implements Transactions
{
    public int $runs = 0;
    /** How many transactions were begun outside any other: what the database would commit. */
    public int $outermost = 0;
    public int $depth = 0;

    public function run(callable $work): mixed
    {
        ++$this->runs;
        if ($this->depth === 0) {
            ++$this->outermost;
        }
        ++$this->depth;
        try {
            return $work();
        } finally {
            --$this->depth;
        }
    }

    public function runRetrying(callable $work): mixed
    {
        return $this->run($work);
    }

    /**
     * What Doctrine does for a pessimistic lock with no transaction open.
     */
    public function requireOpen(): void
    {
        if ($this->depth === 0) {
            throw new \LogicException('A lock was asked for outside a transaction.');
        }
    }
}
