<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Platform\Doctrine\AfterCommit;
use OrillaCart\Platform\Doctrine\DoctrineTransactions;
use OrillaCart\Shared\Persistence\Contention;
use PHPUnit\Framework\TestCase;

final class DoctrineTransactionsTest extends TestCase
{
    private int $tries = 0;

    public function testAnOutermostTransactionTheDatabaseGaveUpOnRunsOnceMore(): void
    {
        $transactions = new DoctrineTransactions($this->entityManager(false), new AfterCommit());
        $tries = 0;

        $result = $transactions->runRetrying(function () use (&$tries): string {
            if (++$tries === 1) {
                throw self::deadlock();
            }

            return 'placed';
        });

        self::assertSame(['placed', 2], [$result, $tries]);
    }

    public function testTwiceIsAReadableRefusal(): void
    {
        $transactions = new DoctrineTransactions($this->entityManager(false), new AfterCommit());

        $this->expectException(Contention::class);
        $transactions->runRetrying(static fn() => throw self::deadlock());
    }

    public function testInsideOtherWorkNothingIsTriedAgain(): void
    {
        $transactions = new DoctrineTransactions($this->entityManager(true), new AfterCommit());

        try {
            $transactions->runRetrying(function (): never {
                ++$this->tries;

                throw self::deadlock();
            });
        } catch (DeadlockException) {
        }

        self::assertSame(1, $this->tries, 'the outermost transaction decides');
    }

    private function entityManager(bool $inTransaction): EntityManagerInterface
    {
        $connection = self::createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn($inTransaction);
        $connection->method('getTransactionNestingLevel')->willReturn($inTransaction ? 1 : 0);
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return $entityManager;
    }

    private static function deadlock(): DeadlockException
    {
        return new DeadlockException(new class ('Deadlock found when trying to get lock') extends \RuntimeException implements DriverException {
            public function getSQLState(): string
            {
                return '40001';
            }
        }, null);
    }
}
