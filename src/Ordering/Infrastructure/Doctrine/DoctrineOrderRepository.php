<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderStatus;

final readonly class DoctrineOrderRepository implements OrderRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id, bool $lock = false): ?Order
    {
        $order = $this->entityManager->find(Order::class, $id);
        if ($order !== null && $lock) {
            // Read again under the lock: a copy loaded earlier in the request may be older than the row.
            $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
        }

        return $order;
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

    public function hasStandingOrder(?int $customerId, string $email, ?int $exceptOrderId = null): bool
    {
        $query = $this->entityManager->createQueryBuilder()->select('o.id')->from(Order::class, 'o')
            ->where('o.status NOT IN (:closed)')->setParameter('closed', [OrderStatus::Cancelled, OrderStatus::Failed])
            ->andWhere($customerId === null ? 'o.email = :email' : 'o.email = :email OR o.customerId = :customer')
            ->setParameter('email', $email)
            ->setMaxResults(1);
        if ($customerId !== null) {
            $query->setParameter('customer', $customerId);
        }
        if ($exceptOrderId !== null) {
            $query->andWhere('o.id <> :except')->setParameter('except', $exceptOrderId);
        }

        return $query->getQuery()->getOneOrNullResult() !== null;
    }

    public function idsAfter(int $afterId, int $limit): array
    {
        $ids = $this->entityManager->createQueryBuilder()->select('o.id')->from(Order::class, 'o')
            ->where('o.id > :after')->setParameter('after', $afterId)
            ->orderBy('o.id')->setMaxResults($limit)
            ->getQuery()->getSingleColumnResult();

        return array_values(array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids));
    }

    public function save(Order $order): void
    {
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }
}
