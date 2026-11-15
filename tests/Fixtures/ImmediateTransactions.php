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
    /** @var list<array{int, string}> the rows locked, by the outermost transaction they were locked in: [n, 'order:5'] */
    public array $locks = [];

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
     * A repository locked a row, as SELECT … FOR UPDATE would: kept, to check the order rows are locked in.
     */
    public function locked(string $row): void
    {
        $this->requireOpen();
        $this->locks[] = [$this->outermost, $row];
    }

    /**
     * A repository saved or removed something (PLAN 732): work that changes the shop's carts, orders and payments
     * runs in a transaction, so what is done in several steps is saved whole or not at all. A write outside one
     * fails the test that made it; a test that writes to a repository itself does so inside run().
     */
    public function written(string $what): void
    {
        if ($this->depth === 0) {
            throw new \LogicException(\sprintf('%s was written outside a transaction: change it through its service, or inside Transactions::run().', $what));
        }
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
