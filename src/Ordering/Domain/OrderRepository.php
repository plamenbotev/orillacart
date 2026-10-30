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
     * Unpaid orders on hold, placed in the time given, with one of the payment methods.
     *
     * @param list<string> $gateways
     *
     * @return list<int> their ids
     */
    public function unpaidOnHold(\DateTimeImmutable $placedFrom, \DateTimeImmutable $placedUntil, array $gateways): array;

    /**
     * A page of the orders that match, in the order asked for, and how many match in all.
     *
     * @return array{list<Order>, int}
     */
    public function search(OrderSearch $search, int $offset, int $limit): array;

    /**
     * The next orders the search finds after the given one, in the search's order, uncounted: for walking every
     * match a batch at a time, so an order neither repeats nor is skipped as orders come and go meanwhile. Their
     * lines, with their taxes and adjustments, and notes are loaded with them, a query each.
     *
     * @param Order|null $after the last of the batch before; null for the first
     *
     * @return list<Order>
     */
    public function searchAfter(OrderSearch $search, ?Order $after, int $limit): array;

    /**
     * Whether the customer, by account or by email, has an order that was not cancelled or did not fail.
     *
     * @param int|null $exceptOrderId an order not to count: the one being priced again
     */
    public function hasStandingOrder(?int $customerId, string $email, ?int $exceptOrderId = null): bool;

    /**
     * Which of the products the customer paid for, by account or by email, in an order not cancelled or failed.
     *
     * @param list<int> $productIds
     *
     * @return list<int>
     */
    public function boughtProducts(?int $customerId, string $email, array $productIds): array;

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

    /**
     * What customers paid for their orders in a currency, and what went back, with when they first and last
     * ordered (orders not cancelled or failed).
     *
     * @param list<int> $customerIds
     *
     * @return array<int, array{paid_orders: int, paid: string, refunded: string, first: ?\DateTimeImmutable, last: ?\DateTimeImmutable}> by customer id; only those with orders
     */
    public function spendByCustomers(array $customerIds, string $currency): array;

    /**
     * Orders not cancelled, failed or refunded with a delivery day from $from to $until, counted by day and slot.
     *
     * @param string $carrier the start of their delivery keys: "5:"
     *
     * @return array<string, int> by "2026-10-21|morning"
     */
    public function deliveriesBooked(string $carrier, \DateTimeImmutable $from, \DateTimeImmutable $until): array;

    /**
     * Marks the order for an add-on's filter; marking it again changes nothing.
     *
     * @return bool false when there is no such order
     */
    public function mark(int $orderId, string $mark): bool;

    public function unmark(int $orderId, string $mark): void;

    public function isMarked(int $orderId, string $mark): bool;

    /**
     * What the shop's orders have used, for the list's filters: each once, in order.
     *
     * @return array{shipping: list<string>, countries: list<string>, currencies: list<string>}
     */
    public function facets(): array;

    public function save(Order $order): void;

    public function remove(Order $order): void;
}
