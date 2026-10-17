<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures;

use OrillaCart\Shared\Persistence\Transactions;

/**
 * Runs the work as it is: in-memory repositories have nothing to roll back.
 */
final class ImmediateTransactions implements Transactions
{
    public int $runs = 0;

    public function run(callable $work): mixed
    {
        ++$this->runs;

        return $work();
    }
}
