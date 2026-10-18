<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Shared\Persistence\Contention;
use OrillaCart\Shared\Persistence\Transactions;

/**
 * A database transaction around the work, saving the entity manager's changes before it commits. Work inside
 * other work runs in a savepoint. Unlike EntityManager::wrapInTransaction(), a failure does not close the entity
 * manager: an expected refusal, such as stock running out at checkout, leaves the request able to go on. When the
 * outermost transaction rolls back, the entity manager forgets what it had loaded, since those copies may hold
 * changes the database never got. Work deferred to AfterCommit runs once the outermost transaction commits.
 */
final readonly class DoctrineTransactions implements Transactions
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AfterCommit $afterCommit,
    ) {}

    public function runRetrying(callable $work): mixed
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            // Inside other work: only the outermost transaction can be tried again.
            return $this->run($work);
        }
        try {
            return $this->run($work);
        } catch (RetryableException) {
            try {
                return $this->run($work);
            } catch (RetryableException $e) {
                throw new Contention($e);
            }
        }
    }

    public function run(callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $this->afterCommit->begin();
        try {
            $result = $work();
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $this->afterCommit->rollBack();
            $connection->rollBack();
            if ($connection->getTransactionNestingLevel() === 0) {
                $this->entityManager->clear();
            }

            throw $e;
        }
        $this->afterCommit->commit();

        return $result;
    }
}
