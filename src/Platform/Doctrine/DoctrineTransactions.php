<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Shared\Persistence\Transactions;

/**
 * A database transaction around the work, saving the entity manager's changes before it commits. Work inside
 * other work runs in a savepoint. Unlike EntityManager::wrapInTransaction(), a failure does not close the entity
 * manager: an expected refusal, such as stock running out at checkout, leaves the request able to go on. When the
 * outermost transaction rolls back, the entity manager forgets what it had loaded, since those copies may hold
 * changes the database never got.
 */
final readonly class DoctrineTransactions implements Transactions
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function run(callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $this->entityManager->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();
            if ($connection->getTransactionNestingLevel() === 0) {
                $this->entityManager->clear();
            }

            throw $e;
        }
    }
}
