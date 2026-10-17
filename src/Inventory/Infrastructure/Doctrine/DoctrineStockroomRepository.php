<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Inventory\Domain\Stockroom;
use OrillaCart\Inventory\Domain\StockroomRepository;

final readonly class DoctrineStockroomRepository implements StockroomRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        /** @var list<Stockroom> */
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Stockroom::class, 's')
            ->orderBy('s.name')
            ->addOrderBy('s.id')
            ->getQuery()
            ->getResult();
    }

    public function find(int $id): ?Stockroom
    {
        return $this->entityManager->find(Stockroom::class, $id);
    }

    public function save(Stockroom $stockroom): void
    {
        $this->entityManager->persist($stockroom);
        $this->entityManager->flush();
    }

    public function remove(Stockroom $stockroom): void
    {
        // The database removes the stockroom's levels (ON DELETE CASCADE).
        $this->entityManager->remove($stockroom);
        $this->entityManager->flush();
    }
}
