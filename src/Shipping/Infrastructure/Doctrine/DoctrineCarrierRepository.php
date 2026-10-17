<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\CarrierRepository;

final readonly class DoctrineCarrierRepository implements CarrierRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        /** @var list<Carrier> */
        return $this->entityManager->createQueryBuilder()
            ->select('c', 'r')
            ->from(Carrier::class, 'c')
            ->leftJoin('c.rates', 'r')
            ->orderBy('c.position')
            ->addOrderBy('c.name')
            ->addOrderBy('c.id')
            ->getQuery()
            ->getResult();
    }

    public function find(int $id): ?Carrier
    {
        return $this->entityManager->find(Carrier::class, $id);
    }

    public function save(Carrier $carrier): void
    {
        $this->entityManager->persist($carrier);
        $this->entityManager->flush();
    }

    public function remove(Carrier $carrier): void
    {
        $this->entityManager->remove($carrier);
        $this->entityManager->flush();
    }
}
