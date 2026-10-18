<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Jobs\Domain\FailedJob;
use OrillaCart\Jobs\Domain\Job;
use OrillaCart\Jobs\Domain\JobStat;
use OrillaCart\Jobs\Domain\JobWorker;

/**
 * The connection and the prefixed names of the job tables, for the SQL the ORM cannot write.
 */
final readonly class JobTables
{
    public const string MOMENT = 'Y-m-d H:i:s';

    public function __construct(private EntityManagerInterface $entityManager) {}

    public function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function jobs(): string
    {
        return $this->entityManager->getClassMetadata(Job::class)->getTableName();
    }

    public function failed(): string
    {
        return $this->entityManager->getClassMetadata(FailedJob::class)->getTableName();
    }

    public function stats(): string
    {
        return $this->entityManager->getClassMetadata(JobStat::class)->getTableName();
    }

    public function workers(): string
    {
        return $this->entityManager->getClassMetadata(JobWorker::class)->getTableName();
    }
}
