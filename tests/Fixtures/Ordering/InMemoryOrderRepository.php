<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Ordering;

use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderSearch;
use OrillaCart\Ordering\Domain\OrderStatus;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;

final class InMemoryOrderRepository implements OrderRepository
{
    private int $lastGrantId = 0;
    private int $lastNoteId = 0;

    /** @var array<int, Order> */
    public array $orders = [];
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
        $text = $search->text;
        $found = array_values(array_filter($this->orders, static fn(Order $order): bool => ($search->status === null || $order->status() === $search->status)
            && (!$search->attentionOnly || $order->attention() !== null)
            && ($search->customerId === null || $order->customerId() === $search->customerId)
            && ($search->who === null || ($search->who === OrderSearch::GUESTS) === ($order->customerId() === null))
            && ($search->placedFrom === null || $order->placedAt() >= $search->placedFrom)
            && ($search->placedBefore === null || $order->placedAt() < $search->placedBefore)
            && ($search->gateway === null || $order->paymentGateway() === $search->gateway)
            && ($search->minTotal === null || $order->total()->compare($search->minTotal) >= 0)
            && ($search->maxTotal === null || $order->total()->compare($search->maxTotal) <= 0)
            && ($text === null || str_contains(strtolower($order->email() . ' ' . $order->billing()->firstName() . ' ' . $order->billing()->lastName()), strtolower($text)) || ltrim($text, '#') === (string) $order->id())));
        usort($found, static function (Order $a, Order $b) use ($search): int {
            $by = $search->sortBy === OrderSearch::BY_TOTAL ? $a->total()->compare($b->total()) : 0;
            $by = $by !== 0 ? $by : $a->id() <=> $b->id();

            return $search->ascending ? $by : -$by;
        });

        return [\array_slice($found, $offset, $limit), \count($found)];
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
