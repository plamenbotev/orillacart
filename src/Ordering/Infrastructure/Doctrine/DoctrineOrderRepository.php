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

    public function search(?OrderStatus $status, ?string $text, ?int $customerId, int $offset, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()->from(Order::class, 'o');
        if ($status !== null) {
            $query->andWhere('o.status = :status')->setParameter('status', $status);
        }
        if ($customerId !== null) {
            $query->andWhere('o.customerId = :customer')->setParameter('customer', $customerId);
        }
        if ($text !== null) {
            $like = '%' . addcslashes($text, '%_\\') . '%';
            $matches = ['o.email LIKE :text', 'o.billing.firstName LIKE :text', 'o.billing.lastName LIKE :text', 'o.billing.phone LIKE :text'];
            $number = ltrim($text, '#');
            if (ctype_digit($number) && \strlen($number) <= 9) {
                $matches[] = 'o.id = :number';
                $query->setParameter('number', (int) $number);
            }
            $query->andWhere(implode(' OR ', $matches))->setParameter('text', $like);
        }

        $total = (int) (clone $query)->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();
        /** @var list<Order> $orders */
        $orders = $query->select('o')
            ->orderBy('o.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [$orders, $total];
    }

    public function remove(Order $order): void
    {
        $this->entityManager->remove($order);
        $this->entityManager->flush();
    }

    public function save(Order $order): void
    {
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }
}
