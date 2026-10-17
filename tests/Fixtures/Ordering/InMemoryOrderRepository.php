<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Ordering;

use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderStatus;

final class InMemoryOrderRepository implements OrderRepository
{
    /** @var array<int, Order> */
    public array $orders = [];
    private int $nextId = 1;

    public function find(int $id): ?Order
    {
        return $this->orders[$id] ?? null;
    }

    public function findByKey(string $key): ?Order
    {
        foreach ($this->orders as $order) {
            if ($order->key() === $key) {
                return $order;
            }
        }

        return null;
    }

    public function pendingPaymentSince(\DateTimeImmutable $before): array
    {
        return array_values(array_filter($this->orders, static fn(Order $order): bool => $order->status() === OrderStatus::PendingPayment && $order->placedAt() < $before));
    }

    public function save(Order $order): void
    {
        $id = new \ReflectionProperty(Order::class, 'id');
        if ($id->getValue($order) === null) {
            $id->setValue($order, $this->nextId++);
        }
        $this->orders[$order->id()] = $order;
    }
}
