<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderStatus;

final readonly class DoctrineOrderRepository implements OrderRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?Order
    {
        return $this->entityManager->find(Order::class, $id);
    }

    public function findByKey(string $key): ?Order
    {
        return $this->entityManager->getRepository(Order::class)->findOneBy(['key' => $key]);
    }

    public function pendingPaymentSince(\DateTimeImmutable $before): array
    {
        /** @var list<Order> */
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.status = :status')
            ->andWhere('o.placedAt < :before')
            ->setParameter('status', OrderStatus::PendingPayment)
            ->setParameter('before', $before)
            ->orderBy('o.id')
            ->getQuery()
            ->getResult();
    }

    public function save(Order $order): void
    {
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }
}
