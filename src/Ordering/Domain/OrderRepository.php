<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

interface OrderRepository
{
    public function find(int $id): ?Order;

    public function findByKey(string $key): ?Order;

    /**
     * Orders still waiting for a provider payment that were placed before the moment.
     *
     * @return list<Order>
     */
    public function pendingPaymentSince(\DateTimeImmutable $before): array;

    public function save(Order $order): void;
}
