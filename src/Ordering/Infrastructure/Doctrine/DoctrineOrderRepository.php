<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use OrillaCart\Ordering\Domain\ChargeLine;
use OrillaCart\Ordering\Domain\DownloadGrant;
use OrillaCart\Ordering\Domain\MarkedOrder;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderFacet;
use OrillaCart\Ordering\Domain\OrderLine;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderSearch;
use OrillaCart\Ordering\Domain\OrderStatus;

final readonly class DoctrineOrderRepository implements OrderRepository
{
    /** The way an order ships as the shop names it: its own words where the customer read another language's. */
    private const string SHOP_SHIPPING = "COALESCE(JSON_VALUE(o.shopWords, '$.shipping_name'), o.shippingName)";

    public function __construct(private EntityManagerInterface $entityManager)
    {
        // DQL has no JSON functions of its own; the shop's words are a JSON column.
        $entityManager->getConfiguration()->addCustomStringFunction(JsonValue::NAME, JsonValue::class);
    }

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

    public function deliveriesBooked(string $carrier, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        /** @var list<array{day: \DateTimeImmutable, slot: string, orders: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('o.deliveryDate AS day, o.deliveryKey AS slot, COUNT(o.id) AS orders')
            ->from(Order::class, 'o')
            ->where('o.deliveryKey LIKE :carrier')
            ->andWhere('o.deliveryDate >= :from AND o.deliveryDate <= :until')
            ->andWhere('o.status NOT IN (:closed)')
            ->setParameter('carrier', addcslashes($carrier, '%_') . '%')
            ->setParameter('from', $from, 'date_immutable')
            ->setParameter('until', $until, 'date_immutable')
            ->setParameter('closed', [OrderStatus::Cancelled, OrderStatus::Failed, OrderStatus::Refunded])
            ->groupBy('o.deliveryDate, o.deliveryKey')
            ->getQuery()
            ->getResult();
        $booked = [];
        foreach ($rows as $row) {
            $booked[$row['day']->format('Y-m-d') . '|' . substr($row['slot'], \strlen($carrier))] = (int) $row['orders'];
        }

        return $booked;
    }

    /** The orders the search finds, as a query with no select yet. */
    private function filtered(OrderSearch $search): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()->from(Order::class, 'o');
        $text = $search->text;
        $lines = 'SELECT 1 FROM ' . OrderLine::class . ' %1$s WHERE %1$s.order = o AND (%2$s)';
        if ($search->attentionOnly) {
            // Set and cleared with the reason, and indexed.
            $query->andWhere('o.attentionSince IS NOT NULL');
        }
        if ($search->statuses !== []) {
            $query->andWhere('o.status IN (:statuses)')->setParameter('statuses', array_map(static fn(OrderStatus $status): string => $status->value, $search->statuses));
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
        match ($search->paid) {
            OrderSearch::UNPAID => $query->andWhere('o.paidAt IS NULL'),
            OrderSearch::PAID => $query->andWhere('o.paidAt IS NOT NULL AND o.refundedTotal = 0'),
            OrderSearch::PARTLY_REFUNDED => $query->andWhere('o.refundedTotal > 0 AND o.refundedTotal < o.total'),
            OrderSearch::REFUNDED => $query->andWhere('o.refundedTotal > 0 AND o.refundedTotal >= o.total'),
            default => null,
        };
        if ($search->shipped !== null) {
            $query->andWhere($search->shipped ? 'o.fulfilledAt IS NOT NULL' : 'o.fulfilledAt IS NULL AND o.shippingName IS NOT NULL');
        }
        if ($search->shippingName !== null) {
            $query->andWhere(self::SHOP_SHIPPING . ' = :shipping')->setParameter('shipping', $search->shippingName);
        }
        if ($search->country !== null) {
            $query->andWhere('o.billing.country = :country OR o.shippingAddress.country = :country')->setParameter('country', $search->country);
        }
        if ($search->deliveryFrom !== null) {
            $query->andWhere('o.deliveryDate >= :deliveryFrom')->setParameter('deliveryFrom', $search->deliveryFrom, 'date_immutable');
        }
        if ($search->deliveryUntil !== null) {
            $query->andWhere('o.deliveryDate <= :deliveryUntil')->setParameter('deliveryUntil', $search->deliveryUntil, 'date_immutable');
        }
        if ($search->currency !== null) {
            $query->andWhere('o.currency = :currency')->setParameter('currency', $search->currency);
        }
        foreach ($search->facts as $fact) {
            match ($fact) {
                OrderSearch::GIFT_CARD => $query->andWhere('o.giftCardTotal > 0'),
                OrderSearch::BACKORDER => $query->andWhere('EXISTS (' . \sprintf($lines, 'bl', 'bl.backordered > 0') . ')'),
                OrderSearch::PERSONALISED => $query->andWhere('EXISTS (' . \sprintf($lines, 'pl', 'pl.personalisation IS NOT NULL') . ')'),
                OrderSearch::CUSTOMER_NOTE => $query->andWhere("o.note <> ''"),
                OrderSearch::DOWNLOADS => $query->andWhere('EXISTS (SELECT 1 FROM ' . DownloadGrant::class . ' g WHERE g.order = o)'),
                default => null,
            };
        }
        foreach (array_values($search->addOnMarks) as $index => $mark) {
            $query->andWhere('EXISTS (SELECT 1 FROM ' . MarkedOrder::class . " am{$index} WHERE am{$index}.orderId = o.id AND am{$index}.mark = :addOnMark{$index})")
                ->setParameter("addOnMark{$index}", $mark);
        }
        foreach (array_values($search->markedIds) as $index => $ids) {
            // None marked matches none: 0 is no order's id.
            $query->andWhere("o.id IN (:marked{$index})")->setParameter("marked{$index}", $ids === [] ? [0] : $ids);
        }
        if ($text !== null) {
            $like = '%' . addcslashes($text, '%_\\') . '%';
            $matches = ['o.email LIKE :text', 'o.vatNumber LIKE :text', 'o.transactionId LIKE :text'];
            foreach (['billing', 'shippingAddress'] as $address) {
                foreach (['firstName', 'lastName', 'phone', 'street', 'city', 'postcode'] as $field) {
                    $matches[] = "o.{$address}.{$field} LIKE :text";
                }
            }
            // An item by the shop's name of it, or the customer's (design 51).
            $matches[] = 'EXISTS (' . \sprintf($lines, 'tl', "tl.name LIKE :text OR JSON_VALUE(tl.shopWords, '$.name') LIKE :text OR tl.sku LIKE :text") . ')';
            $number = ltrim($text, '#');
            if (ctype_digit($number) && \strlen($number) <= 9) {
                $matches[] = 'o.id = :number';
                $query->setParameter('number', (int) $number);
            }
            if ($search->foundIds !== []) {
                $matches[] = 'o.id IN (:found)';
                $query->setParameter('found', $search->foundIds);
            }
            $query->andWhere(implode(' OR ', $matches))->setParameter('text', $like);
        }

        return $query;
    }

    public function search(OrderSearch $search, int $offset, int $limit): array
    {
        $query = $this->filtered($search);
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
        // A list shows how many items each order has: their lines in one query, not one per row.
        $this->load($orders, ['lines']);

        return [$orders, $total];
    }

    public function searchAfter(OrderSearch $search, ?Order $after, int $limit): array
    {
        $query = $this->filtered($search);
        $direction = $search->ascending ? 'ASC' : 'DESC';
        $beyond = $search->ascending ? '>' : '<';
        if ($search->sortBy === OrderSearch::BY_TOTAL) {
            $query->orderBy('o.total', $direction)->addOrderBy('o.id', $direction);
            if ($after !== null) {
                $query->andWhere("o.total {$beyond} :afterTotal OR (o.total = :afterTotal AND o.id {$beyond} :afterId)")
                    ->setParameter('afterTotal', $after->total()->toFixed())
                    ->setParameter('afterId', $after->id());
            }
        } else {
            $query->orderBy('o.id', $direction);
            if ($after !== null) {
                $query->andWhere("o.id {$beyond} :afterId")->setParameter('afterId', $after->id());
            }
        }
        /** @var list<Order> $orders */
        $orders = $query->select('o')->setMaxResults($limit)->getQuery()->getResult();
        $this->load($orders, ['lines', 'notes']);
        $this->loadLines($orders);

        return $orders;
    }

    /**
     * Loads the orders' collections in one query each, instead of one per order as each is read.
     *
     * @param list<Order>  $orders
     * @param list<string> $collections
     */
    private function load(array $orders, array $collections): void
    {
        if ($orders === []) {
            return;
        }
        $ids = array_map(static fn(Order $order): int => $order->id(), $orders);
        foreach ($collections as $collection) {
            $this->entityManager->createQueryBuilder()->select('o', 'c')->from(Order::class, 'o')
                ->leftJoin("o.{$collection}", 'c')
                ->where('o.id IN (:ids)')->setParameter('ids', $ids)
                ->getQuery()->getResult();
        }
    }

    /**
     * Loads the taxes and adjustments of the orders' lines, one query each.
     *
     * @param list<Order> $orders
     */
    private function loadLines(array $orders): void
    {
        if ($orders === []) {
            return;
        }
        $ids = array_map(static fn(Order $order): int => $order->id(), $orders);
        foreach (['taxes', 'adjustments'] as $collection) {
            $this->entityManager->createQueryBuilder()->select('l', 'c')->from(OrderLine::class, 'l')
                ->leftJoin("l.{$collection}", 'c')
                ->where('IDENTITY(l.order) IN (:ids)')->setParameter('ids', $ids)
                ->getQuery()->getResult();
        }
    }

    public function mark(int $orderId, string $mark): bool
    {
        $marked = new MarkedOrder($orderId, $mark);
        if ($this->entityManager->find(Order::class, $orderId) === null) {
            return false;
        }
        if (!$this->isMarked($orderId, $mark)) {
            try {
                $this->entityManager->persist($marked);
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // Marked by another request meanwhile: it is marked.
            }
        }

        return true;
    }

    public function unmark(int $orderId, string $mark): void
    {
        $this->entityManager->createQueryBuilder()->delete(MarkedOrder::class, 'm')
            ->where('m.orderId = :order AND m.mark = :mark')
            ->setParameter('order', $orderId)
            ->setParameter('mark', $mark)
            ->getQuery()
            ->execute();
    }

    public function isMarked(int $orderId, string $mark): bool
    {
        return $this->entityManager->getRepository(MarkedOrder::class)->count(['orderId' => $orderId, 'mark' => $mark]) > 0;
    }

    public function facets(): array
    {
        $facets = [OrderFacet::SHIPPING => [], OrderFacet::COUNTRY => [], OrderFacet::CURRENCY => []];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative('SELECT kind, value FROM ' . $this->facetTable() . ' ORDER BY kind, value') as $row) {
            if (\is_string($row['kind'] ?? null) && \is_string($row['value'] ?? null) && isset($facets[$row['kind']])) {
                $facets[$row['kind']][] = $row['value'];
            }
        }

        return ['shipping' => $facets[OrderFacet::SHIPPING], 'countries' => $facets[OrderFacet::COUNTRY], 'currencies' => $facets[OrderFacet::CURRENCY]];
    }

    public function ofPerson(string $email, ?int $customerId, int $offset, int $limit): array
    {
        // By email and by account apart, each by its index, then the page of their ids together.
        $ids = $this->ids('o.email = :email', ['email' => $email]);
        if ($customerId !== null) {
            $ids = array_values(array_unique([...$ids, ...$this->ids('o.customerId = :customer', ['customer' => $customerId])]));
        }
        sort($ids);
        $ids = \array_slice($ids, $offset, $limit);
        if ($ids === []) {
            return [];
        }

        /** @var list<Order> */
        return $this->entityManager->createQueryBuilder()->select('o')->from(Order::class, 'o')
            ->where('o.id IN (:ids)')->setParameter('ids', $ids)
            ->orderBy('o.id', 'ASC')
            ->getQuery()->getResult();
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

    public function spendByCustomers(array $customerIds, string $currency): array
    {
        if ($customerIds === []) {
            return [];
        }
        $rows = $this->entityManager->createQueryBuilder()
            ->select('o.customerId AS customer, MIN(o.placedAt) AS first, MAX(o.placedAt) AS last')
            ->addSelect('SUM(CASE WHEN o.paidAt IS NOT NULL AND o.currency = :currency THEN 1 ELSE 0 END) AS paid_orders')
            ->addSelect('SUM(CASE WHEN o.paidAt IS NOT NULL AND o.currency = :currency THEN o.paidAmount ELSE 0 END) AS paid')
            ->addSelect('SUM(CASE WHEN o.paidAt IS NOT NULL AND o.currency = :currency THEN o.refundedTotal ELSE 0 END) AS refunded')
            ->from(Order::class, 'o')
            ->where('o.customerId IN (:customers)')->setParameter('customers', $customerIds)
            ->andWhere('o.status NOT IN (:unplaced)')->setParameter('unplaced', [OrderStatus::Cancelled, OrderStatus::Failed])
            ->setParameter('currency', $currency)
            ->groupBy('o.customerId')->getQuery()->getScalarResult();
        $spend = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !is_numeric($row['customer'] ?? null)) {
                continue;
            }
            $spend[(int) $row['customer']] = [
                'paid_orders' => is_numeric($row['paid_orders'] ?? null) ? (int) $row['paid_orders'] : 0,
                'paid' => is_numeric($row['paid'] ?? null) ? (string) $row['paid'] : '0',
                'refunded' => is_numeric($row['refunded'] ?? null) ? (string) $row['refunded'] : '0',
                'first' => \is_string($row['first'] ?? null) ? new \DateTimeImmutable($row['first'], new \DateTimeZone('UTC')) : null,
                'last' => \is_string($row['last'] ?? null) ? new \DateTimeImmutable($row['last'], new \DateTimeZone('UTC')) : null,
            ];
        }

        return $spend;
    }

    public function hasStandingOrder(?int $customerId, string $email, ?int $exceptOrderId = null): bool
    {
        // The email and the account each by its index: one query with OR between them reads every order.
        $standing = function (string $who, string $name, int|string $value) use ($exceptOrderId): bool {
            $query = $this->entityManager->createQueryBuilder()->select('o.id')->from(Order::class, 'o')
                ->where($who)->setParameter($name, $value)
                ->andWhere('o.status NOT IN (:closed)')->setParameter('closed', [OrderStatus::Cancelled, OrderStatus::Failed])
                ->setMaxResults(1);
            if ($exceptOrderId !== null) {
                $query->andWhere('o.id <> :except')->setParameter('except', $exceptOrderId);
            }

            return $query->getQuery()->getOneOrNullResult() !== null;
        };

        return $standing('o.email = :email', 'email', $email) || ($customerId !== null && $standing('o.customerId = :customer', 'customer', $customerId));
    }

    public function boughtProducts(?int $customerId, string $email, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        // The person's orders by each index apart, then their lines.
        $orders = $this->ids('o.email = :email', ['email' => $email]);
        if ($customerId !== null) {
            $orders = array_values(array_unique([...$orders, ...$this->ids('o.customerId = :customer', ['customer' => $customerId])]));
        }
        if ($orders === []) {
            return [];
        }
        $ids = $this->entityManager->createQueryBuilder()->select('DISTINCT l.productId')->from(OrderLine::class, 'l')->join('l.order', 'o')
            ->where('l.productId IN (:products)')->setParameter('products', $productIds)
            ->andWhere('o.id IN (:orders)')->setParameter('orders', $orders)
            ->andWhere('o.paidAt IS NOT NULL')
            ->andWhere('o.status NOT IN (:closed)')->setParameter('closed', [OrderStatus::Cancelled, OrderStatus::Failed])
            ->getQuery()->getSingleColumnResult();

        return array_values(array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids));
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
        $this->keepFacets($order);
    }

    /**
     * The order's values for the Orders screen's filters, added where they are new, in one statement: in the
     * order's transaction, so they go when it rolls back.
     */
    private function keepFacets(Order $order): void
    {
        $facets = OrderFacet::of($order);
        $this->entityManager->getConnection()->executeStatement(
            'INSERT IGNORE INTO ' . $this->facetTable() . ' (kind, value) VALUES ' . implode(', ', array_fill(0, \count($facets), '(?, ?)')),
            array_merge(...array_map(static fn(OrderFacet $facet): array => [$facet->kind, $facet->value], $facets)),
        );
    }

    private function facetTable(): string
    {
        return $this->entityManager->getClassMetadata(OrderFacet::class)->getTableName();
    }

    /**
     * The ids of the orders matching one indexed condition.
     *
     * @param array<string, int|string> $parameters
     *
     * @return list<int>
     */
    private function ids(string $condition, array $parameters): array
    {
        $query = $this->entityManager->createQueryBuilder()->select('o.id')->from(Order::class, 'o')->where($condition);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        return array_values(array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $query->getQuery()->getSingleColumnResult()));
    }

    public function countChargesTaxedBy(int $taxGroupId): int
    {
        $count = $this->entityManager->createQuery('SELECT COUNT(l.id) FROM ' . ChargeLine::class . ' l JOIN l.order o WHERE l.chargeTaxGroupId = :group AND o.status IN (:open)')
            ->setParameter('group', $taxGroupId)
            ->setParameter('open', [OrderStatus::OnHold, OrderStatus::PendingPayment])
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }
}
