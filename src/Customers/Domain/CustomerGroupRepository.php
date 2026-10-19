<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

interface CustomerGroupRepository
{
    /** @return list<CustomerGroup> by name */
    public function all(): array;

    public function find(int $id): ?CustomerGroup;

    public function findByName(string $name): ?CustomerGroup;

    /** @return list<int> the groups the customer is in */
    public function groupIdsOf(int $customerId): array;

    /**
     * The groups of many customers, in one query.
     *
     * @param list<int> $customerIds
     *
     * @return array<int, list<int>> by customer id; those in no group left out
     */
    public function groupIdsOfMany(array $customerIds): array;

    /** @return array<int, int> how many customers each group has, by group id, counted without loading them */
    public function memberCounts(): array;

    public function save(CustomerGroup $group): void;

    public function delete(CustomerGroup $group): void;
}
