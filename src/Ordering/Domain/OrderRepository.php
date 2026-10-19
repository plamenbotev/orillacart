<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

interface OrderRepository
{
    /**
     * @param bool $lock whether to lock the order's row until the transaction ends (SELECT … FOR UPDATE); only
     *                   inside a transaction
     */
    public function find(int $id, bool $lock = false): ?Order;


    /**
     * Orders still waiting for a provider payment since before the moment (placed then, or asked to pay again).
     *
     * @return list<Order>
     */
    public function pendingPaymentSince(\DateTimeImmutable $before): array;

    /**
     * A page of the orders that match, in the order asked for, and how many match in all.
     *
     * @return array{list<Order>, int}
     */
    public function search(OrderSearch $search, int $offset, int $limit): array;

    /**
     * Whether the customer, by account or by email, has an order that was not cancelled or did not fail.
     *
     * @param int|null $exceptOrderId an order not to count: the one being priced again
     */
    public function hasStandingOrder(?int $customerId, string $email, ?int $exceptOrderId = null): bool;

    /**
     * The ids of the orders after the given id, in order: for going through every order a batch at a time.
     *
     * @return list<int>
     */
    public function idsAfter(int $afterId, int $limit): array;

    /**
     * A page of the orders of a person, by their email or their account, oldest first.
     *
     * @return list<Order>
     */
    public function ofPerson(string $email, ?int $customerId, int $offset, int $limit): array;

    /**
     * How many orders each customer has, in one query.
     *
     * @param list<int> $customerIds
     *
     * @return array<int, int> by customer id; those without orders left out
     */
    public function countByCustomers(array $customerIds): array;

    public function save(Order $order): void;

    public function remove(Order $order): void;
}
