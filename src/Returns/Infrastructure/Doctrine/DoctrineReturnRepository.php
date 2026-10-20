<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Returns\Domain\ItemReturn;
use OrillaCart\Returns\Domain\ReturnRepository;

final readonly class DoctrineReturnRepository implements ReturnRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?ItemReturn
    {
        return $this->entityManager->find(ItemReturn::class, $id);
    }

    public function forOrder(int $orderId): array
    {
        /** @var list<ItemReturn> */
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ItemReturn::class, 'r')
            ->where('r.orderId = :order')
            ->setParameter('order', $orderId)
            ->orderBy('r.requestedAt')
            ->addOrderBy('r.id')
            ->getQuery()
            ->getResult();
    }

    public function save(ItemReturn $return): void
    {
        $this->entityManager->persist($return);
        $this->entityManager->flush();
    }
}
