<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Application\Dto\LifetimeValueView;
use OrillaCart\Geography\Application\Dto\AddressData;

/**
 * What the customers' side asks of their orders, which Ordering, above it, answers: how many and what they
 * brought, the addresses they were sent to, the guest orders an account takes in, and what a privacy request
 * exports or erases of them.
 */
interface CustomerOrders
{
    /**
     * @param list<int> $customerIds
     *
     * @return array<int, int> by customer id, for each of them
     */
    public function counts(array $customerIds): array;

    /**
     * @param list<int> $customerIds
     *
     * @return array<int, LifetimeValueView> by customer id, for each of them
     */
    public function lifetimeValues(array $customerIds): array;

    /**
     * The customer's latest order: its number, billing and shipping address; null without orders.
     *
     * @return array{int, AddressData, ?AddressData}|null
     */
    public function latestAddresses(int $customerId): ?array;

    /** @return list<int> the orders placed with the email without an account */
    public function guestOrdersOf(string $email): array;

    /**
     * Moves the order into the account.
     *
     * @return array{AddressData, ?AddressData}|null its billing and shipping address; null for no such order
     */
    public function giveTo(int $orderId, int $customerId): ?array;

    /**
     * A page of the person's orders, for a privacy request to export.
     *
     * @return list<array{group: string, label: string, id: string, data: list<array{name: string, value: string}>}>
     */
    public function exportPage(string $email, ?int $customerId, int $page, int $perPage): array;

    /**
     * Erases the customer from up to that many of the person's orders, which stay for the books.
     *
     * @return int how many
     */
    public function erasePage(string $email, ?int $customerId, int $perPage): int;
}
