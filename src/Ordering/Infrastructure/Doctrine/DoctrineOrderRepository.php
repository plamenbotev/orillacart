<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderSearch;
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

    public function pendingPaymentSince(\DateTimeImmutable $before): array
    {
        /** @var list<Order> */
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.status = :status')
            ->andWhere('COALESCE(o.awaitingPaymentSince, o.placedAt) < :before')
            ->setParameter('status', OrderStatus::PendingPayment)
            ->setParameter('before', $before)
            ->orderBy('o.id')
            ->getQuery()
            ->getResult();
    }

    public function unpaidOnHold(\DateTimeImmutable $placedFrom, \DateTimeImmutable $placedUntil, array $gateways): array
    {
        if ($gateways === []) {
            return [];
        }

        $ids = $this->entityManager->createQueryBuilder()
            ->select('o.id')
            ->from(Order::class, 'o')
            ->where('o.status = :status')
            ->andWhere('o.paidAt IS NULL')
            ->andWhere('o.placedAt >= :from AND o.placedAt <= :until')
            ->andWhere('o.paymentGateway IN (:gateways)')
            ->setParameter('status', OrderStatus::OnHold)
            ->setParameter('from', $placedFrom)
            ->setParameter('until', $placedUntil)
            ->setParameter('gateways', $gateways)
            ->orderBy('o.id')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids));
    }

    public function search(OrderSearch $search, int $offset, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()->from(Order::class, 'o');
        $text = $search->text;
        if ($search->attentionOnly) {
            $query->andWhere('o.attention IS NOT NULL');
        }
        if ($search->status !== null) {
            $query->andWhere('o.status = :status')->setParameter('status', $search->status);
        }
        if ($search->customerId !== null) {
            $query->andWhere('o.customerId = :customer')->setParameter('customer', $search->customerId);
        }
        if ($search->who !== null) {
            $query->andWhere($search->who === OrderSearch::GUESTS ? 'o.customerId IS NULL' : 'o.customerId IS NOT NULL');
        }
        if ($search->placedFrom !== null) {
            $query->andWhere('o.placedAt >= :from')->setParameter('from', $search->placedFrom, 'datetime_immutable');
        }
        if ($search->placedBefore !== null) {
            $query->andWhere('o.placedAt < :before')->setParameter('before', $search->placedBefore, 'datetime_immutable');
        }
        if ($search->gateway !== null) {
            $query->andWhere('o.paymentGateway = :gateway')->setParameter('gateway', $search->gateway);
        }
        if ($search->minTotal !== null) {
            $query->andWhere('o.total >= :min')->setParameter('min', $search->minTotal->toFixed());
        }
        if ($search->maxTotal !== null) {
            $query->andWhere('o.total <= :max')->setParameter('max', $search->maxTotal->toFixed());
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
        $direction = $search->ascending ? 'ASC' : 'DESC';
        if ($search->sortBy === OrderSearch::BY_TOTAL) {
            $query->orderBy('o.total', $direction)->addOrderBy('o.id', $direction);
        } else {
            $query->orderBy('o.id', $direction);
        }
        /** @var list<Order> $orders */
        $orders = $query->select('o')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [$orders, $total];
    }

    public function ofPerson(string $email, ?int $customerId, int $offset, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()->select('o')->from(Order::class, 'o')
            ->where($customerId === null ? 'o.email = :email' : 'o.email = :email OR o.customerId = :customer')
            ->setParameter('email', $email)
            ->orderBy('o.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        if ($customerId !== null) {
            $query->setParameter('customer', $customerId);
        }

        /** @var list<Order> */
        return $query->getQuery()->getResult();
    }

    public function remove(Order $order): void
    {
        $this->entityManager->remove($order);
        $this->entityManager->flush();
    }

    public function countByCustomers(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }
        $rows = $this->entityManager->createQueryBuilder()->select('o.customerId AS customer, COUNT(o.id) AS orders')->from(Order::class, 'o')
            ->where('o.customerId IN (:customers)')->setParameter('customers', $customerIds)
            ->groupBy('o.customerId')->getQuery()->getScalarResult();
        $counts = [];
        foreach ($rows as $row) {
            if (\is_array($row) && is_numeric($row['customer'] ?? null) && is_numeric($row['orders'] ?? null)) {
                $counts[(int) $row['customer']] = (int) $row['orders'];
            }
        }

        return $counts;
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
