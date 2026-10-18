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

    /**
     * A page of orders, newest first, and how many orders match in all.
     *
     * @return array{list<Order>, int}
     */
    public function search(?OrderStatus $status, ?string $text, ?int $customerId, int $offset, int $limit): array;

    /**
     * Whether the customer, by account or by email, has an order that was not cancelled or did not fail.
     */
    public function hasStandingOrder(?int $customerId, string $email): bool;

    /**
     * The ids of the orders after the given id, in order: for going through every order a batch at a time.
     *
     * @return list<int>
     */
    public function idsAfter(int $afterId, int $limit): array;

    public function save(Order $order): void;

    public function remove(Order $order): void;
}
