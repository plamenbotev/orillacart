<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Ordering;

use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderStatus;
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

    public function search(?OrderStatus $status, ?string $text, ?int $customerId, int $offset, int $limit, bool $attentionOnly = false): array
    {
        $found = array_values(array_filter($this->orders, static fn(Order $order): bool => ($status === null || $order->status() === $status)
            && (!$attentionOnly || $order->attention() !== null)
            && ($customerId === null || $order->customerId() === $customerId)
            && ($text === null || str_contains(strtolower($order->email() . ' ' . $order->billing()->firstName() . ' ' . $order->billing()->lastName()), strtolower($text)) || ltrim($text, '#') === (string) $order->id())));
        usort($found, static fn(Order $a, Order $b): int => $b->id() <=> $a->id());

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
