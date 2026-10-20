<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Shipping\Domain\Consignment;
use OrillaCart\Shipping\Domain\ConsignmentRepository;
use OrillaCart\Shipping\Domain\ConsignmentStatus;

final readonly class DoctrineConsignmentRepository implements ConsignmentRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?Consignment
    {
        return $this->entityManager->find(Consignment::class, $id);
    }

    public function forOrder(int $orderId): array
    {
        /** @var list<Consignment> */
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Consignment::class, 'c')
            ->where('c.orderId = :order')
            ->setParameter('order', $orderId)
            ->orderBy('c.bookedAt')
            ->addOrderBy('c.id')
            ->getQuery()
            ->getResult();
    }

    public function open(int $limit): array
    {
        /** @var list<Consignment> */
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Consignment::class, 'c')
            ->where('c.status IN (:open)')
            // Only couriers are asked; what the shop sent itself, the shop says when it arrived.
            ->andWhere('c.method IS NOT NULL')
            ->setParameter('open', [ConsignmentStatus::Booked->value, ConsignmentStatus::OnTheWay->value])
            ->orderBy('c.updatedAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function save(Consignment $consignment): void
    {
        $this->entityManager->persist($consignment);
        $this->entityManager->flush();
    }
}
