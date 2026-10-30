<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Ordering;

use OrillaCart\Ordering\Domain\Address;
use OrillaCart\Ordering\Domain\MarkedOrder;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderLine;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderSearch;
use OrillaCart\Ordering\Domain\OrderStatus;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;

final class InMemoryOrderRepository implements OrderRepository
{
    private int $lastGrantId = 0;
    private int $lastNoteId = 0;
    private int $lastAdjustmentId = 0;

    /** @var array<int, Order> */
    public array $orders = [];
    /** @var array<int, array<string, true>> add-ons' marks, by order */
    private array $marks = [];
    private int $nextId = 1;

    public function __construct(private readonly ?ImmediateTransactions $transactions = null) {}

    public function find(int $id, bool $lock = false): ?Order
    {
        if ($lock) {
            $this->transactions?->requireOpen();
        }

        return $this->orders[$id] ?? null;
    }

    public function idsAfter(int $afterId, int $limit): array
    {
        $ids = array_values(array_filter(array_keys($this->orders), static fn(int $id): bool => $id > $afterId));
        sort($ids);

        return \array_slice($ids, 0, $limit);
    }

    public function pendingPaymentSince(\DateTimeImmutable $before): array
    {
        return array_values(array_filter($this->orders, static fn(Order $order): bool => $order->status() === OrderStatus::PendingPayment && $order->awaitingPaymentSince() < $before));
    }

    public function unpaidOnHold(\DateTimeImmutable $placedFrom, \DateTimeImmutable $placedUntil, array $gateways): array
    {
        return array_values(array_map(static fn(Order $order): int => $order->id(), array_filter($this->orders, static fn(Order $order): bool => $order->status() === OrderStatus::OnHold
            && $order->paidAt() === null
            && $order->placedAt() >= $placedFrom && $order->placedAt() <= $placedUntil
            && \in_array($order->paymentGateway(), $gateways, true))));
    }

    public function deliveriesBooked(string $carrier, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $booked = [];
        foreach ($this->orders as $order) {
            $delivery = $order->delivery();
            if ($delivery?->key === null || !str_starts_with($delivery->key, $carrier) || $order->status()->isClosed()) {
                continue;
            }
            $day = $delivery->date->format('Y-m-d');
            if ($day >= $from->format('Y-m-d') && $day <= $until->format('Y-m-d')) {
                $key = $day . '|' . substr($delivery->key, \strlen($carrier));
                $booked[$key] = ($booked[$key] ?? 0) + 1;
            }
        }

        return $booked;
    }

    public function ofPerson(string $email, ?int $customerId, int $offset, int $limit): array
    {
        $found = array_values(array_filter($this->orders, static fn(Order $order): bool => strcasecmp($order->email(), $email) === 0 || ($customerId !== null && $order->customerId() === $customerId)));
        usort($found, static fn(Order $a, Order $b): int => $a->id() <=> $b->id());

        return \array_slice($found, $offset, $limit);
    }

    public function countByCustomers(array $customerIds): array
    {
        $counts = [];
        foreach ($this->orders as $order) {
            $customer = $order->customerId();
            if ($customer !== null && \in_array($customer, $customerIds, true)) {
                $counts[$customer] = ($counts[$customer] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function spendByCustomers(array $customerIds, string $currency): array
    {
        $spend = [];
        foreach ($this->orders as $order) {
            $customer = $order->customerId();
            if ($customer === null || !\in_array($customer, $customerIds, true) || \in_array($order->status(), [OrderStatus::Cancelled, OrderStatus::Failed], true)) {
                continue;
            }
            $was = $spend[$customer] ?? ['paid_orders' => 0, 'paid' => '0', 'refunded' => '0', 'first' => null, 'last' => null];
            $paid = $order->paidAt() !== null && $order->currency() === $currency;
            $spend[$customer] = [
                'paid_orders' => $was['paid_orders'] + ($paid ? 1 : 0),
                'paid' => $paid ? Money::of($was['paid'])->add($order->paidAmount())->toFixed() : $was['paid'],
                'refunded' => $paid ? Money::of($was['refunded'])->add($order->refundedTotal())->toFixed() : $was['refunded'],
                'first' => $was['first'] === null || $order->placedAt() < $was['first'] ? $order->placedAt() : $was['first'],
                'last' => $was['last'] === null || $order->placedAt() > $was['last'] ? $order->placedAt() : $was['last'],
            ];
        }

        return $spend;
    }

    public function hasStandingOrder(?int $customerId, string $email, ?int $exceptOrderId = null): bool
    {
        foreach ($this->orders as $order) {
            if ($order->id() !== $exceptOrderId && !\in_array($order->status(), [OrderStatus::Cancelled, OrderStatus::Failed], true)
                && (strcasecmp($order->email(), $email) === 0 || ($customerId !== null && $order->customerId() === $customerId))) {
                return true;
            }
        }

        return false;
    }

    public function boughtProducts(?int $customerId, string $email, array $productIds): array
    {
        $bought = [];
        foreach ($this->orders as $order) {
            if ($order->paidAt() === null || \in_array($order->status(), [OrderStatus::Cancelled, OrderStatus::Failed], true)
                || (strcasecmp($order->email(), $email) !== 0 && ($customerId === null || $order->customerId() !== $customerId))) {
                continue;
            }
            foreach ($order->lines() as $line) {
                if (\in_array($line->productId(), $productIds, true)) {
                    $bought[$line->productId()] = $line->productId();
                }
            }
        }

        return array_values($bought);
    }

    public function search(OrderSearch $search, int $offset, int $limit): array
    {
        $found = array_values(array_filter($this->orders, fn(Order $order): bool => self::matches($order, $search)
            && array_filter($search->addOnMarks, fn(string $mark): bool => !$this->isMarked($order->id(), $mark)) === []));
        usort($found, static function (Order $a, Order $b) use ($search): int {
            $by = $search->sortBy === OrderSearch::BY_TOTAL ? $a->total()->compare($b->total()) : 0;
            $by = $by !== 0 ? $by : $a->id() <=> $b->id();

            return $search->ascending ? $by : -$by;
        });

        return [\array_slice($found, $offset, $limit), \count($found)];
    }

    public function mark(int $orderId, string $mark): bool
    {
        new MarkedOrder($orderId, $mark);
        if (!isset($this->orders[$orderId])) {
            return false;
        }
        $this->marks[$orderId][$mark] = true;

        return true;
    }

    public function unmark(int $orderId, string $mark): void
    {
        unset($this->marks[$orderId][$mark]);
    }

    public function isMarked(int $orderId, string $mark): bool
    {
        return isset($this->marks[$orderId][$mark]);
    }

    public function facets(): array
    {
        $shipping = [];
        $countries = [];
        $currencies = [];
        foreach ($this->orders as $order) {
            if (($order->shopShippingName() ?? '') !== '') {
                $shipping[] = (string) $order->shopShippingName();
            }
            $countries[] = $order->billing()->country();
            if ($order->shippingAddress() !== null) {
                $countries[] = $order->shippingAddress()->country();
            }
            $currencies[] = $order->currency();
        }
        return ['shipping' => self::each($shipping), 'countries' => self::each($countries), 'currencies' => self::each($currencies)];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string> each once, in order
     */
    private static function each(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, static fn(string $value): bool => $value !== '')));
        sort($values);

        return $values;
    }

    private static function matches(Order $order, OrderSearch $search): bool
    {
        $text = $search->text === null ? null : mb_strtolower($search->text);
        $paid = $order->paidAt() !== null;
        $refunded = $order->refundedTotal();
        $lines = $order->lines();
        $addresses = array_filter([$order->billing(), $order->shippingAddress()]);
        $haystack = [$order->email(), $order->vatNumber() ?? '', $order->transactionId() ?? ''];
        foreach ($addresses as $address) {
            array_push($haystack, $address->firstName(), $address->lastName(), $address->phone(), $address->street(), $address->city(), $address->postcode());
        }
        foreach ($lines as $line) {
            array_push($haystack, $line->name(), $line->shopName(), $line->sku() ?? '');
        }
        $has = static fn(string $fact): bool => match ($fact) {
            OrderSearch::GIFT_CARD => !$order->giftCardTotal()->isZero(),
            OrderSearch::BACKORDER => array_filter($lines, static fn(OrderLine $line): bool => $line->backordered() > 0) !== [],
            OrderSearch::PERSONALISED => array_filter($lines, static fn(OrderLine $line): bool => $line->personalisation() !== []) !== [],
            OrderSearch::CUSTOMER_NOTE => $order->note() !== '',
            OrderSearch::DOWNLOADS => $order->grants() !== [],
            default => true,
        };
        $delivery = $order->delivery()?->date->format('Y-m-d');

        return ($search->statuses === [] || \in_array($order->status(), $search->statuses, true))
            && (!$search->attentionOnly || $order->attention() !== null)
            && ($search->customerId === null || $order->customerId() === $search->customerId)
            && ($search->who === null || ($search->who === OrderSearch::GUESTS) === ($order->customerId() === null))
            && ($search->placedFrom === null || $order->placedAt() >= $search->placedFrom)
            && ($search->placedBefore === null || $order->placedAt() < $search->placedBefore)
            && ($search->gateway === null || $order->paymentGateway() === $search->gateway)
            && ($search->minTotal === null || $order->total()->compare($search->minTotal) >= 0)
            && ($search->maxTotal === null || $order->total()->compare($search->maxTotal) <= 0)
            && match ($search->paid) {
                OrderSearch::UNPAID => !$paid,
                OrderSearch::PAID => $paid && $refunded->isZero(),
                OrderSearch::PARTLY_REFUNDED => !$refunded->isZero() && $refunded->compare($order->total()) < 0,
                OrderSearch::REFUNDED => !$refunded->isZero() && $refunded->compare($order->total()) >= 0,
                default => true,
            }
        && ($search->shipped === null || ($search->shipped ? $order->fulfilledAt() !== null : $order->fulfilledAt() === null && $order->shippingName() !== null))
        && ($search->shippingName === null || $order->shopShippingName() === $search->shippingName)
        && ($search->country === null || array_filter($addresses, static fn(Address $address): bool => $address->country() === $search->country) !== [])
        && ($search->deliveryFrom === null || ($delivery !== null && $delivery >= $search->deliveryFrom->format('Y-m-d')))
        && ($search->deliveryUntil === null || ($delivery !== null && $delivery <= $search->deliveryUntil->format('Y-m-d')))
        && ($search->currency === null || $order->currency() === $search->currency)
        && array_filter($search->facts, static fn(string $fact): bool => !$has($fact)) === []
        && array_filter($search->markedIds, static fn(array $ids): bool => !\in_array($order->id(), $ids, true)) === []
        && ($text === null
            || array_filter($haystack, static fn(string $value): bool => $value !== '' && str_contains(mb_strtolower($value), $text)) !== []
            || ltrim($text, '#') === (string) $order->id()
            || \in_array($order->id(), $search->foundIds, true));
    }

    public function remove(Order $order): void
    {
        unset($this->orders[$order->id()]);
    }

    public function save(Order $order): void
    {
        // Grants and notes get ids on save, as the database gives them.
        foreach ($order->notes() as $note) {
            $id = new \ReflectionProperty($note, 'id');
            if ($id->getValue($note) === null) {
                $id->setValue($note, ++$this->lastNoteId);
            }
        }
        foreach ($order->adjustments() as $adjustment) {
            $id = new \ReflectionProperty($adjustment, 'id');
            if ($id->getValue($adjustment) === null) {
                $id->setValue($adjustment, ++$this->lastAdjustmentId);
            }
        }
        foreach ($order->grants() as $grant) {
            $id = new \ReflectionProperty($grant, 'id');
            if ($id->getValue($grant) === null) {
                $id->setValue($grant, ++$this->lastGrantId);
            }
        }
        $id = new \ReflectionProperty(Order::class, 'id');
        if ($id->getValue($order) === null) {
            $id->setValue($order, $this->nextId++);
        }
        $this->orders[$order->id()] = $order;
    }
}
