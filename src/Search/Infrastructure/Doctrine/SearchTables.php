<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Search\Domain\IndexedProduct;
use OrillaCart\Search\Domain\IndexedValue;
use OrillaCart\Search\Domain\IndexedVariant;
use OrillaCart\Search\Domain\IndexJob;

/**
 * The connection and the prefixed names of the search tables, for the SQL the ORM cannot write.
 */
final readonly class SearchTables
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function jobs(): string
    {
        return $this->entityManager->getClassMetadata(IndexJob::class)->getTableName();
    }

    public function documents(): string
    {
        return $this->entityManager->getClassMetadata(IndexedProduct::class)->getTableName();
    }

    public function variants(): string
    {
        return $this->entityManager->getClassMetadata(IndexedVariant::class)->getTableName();
    }

    public function values(): string
    {
        return $this->entityManager->getClassMetadata(IndexedValue::class)->getTableName();
    }
}
