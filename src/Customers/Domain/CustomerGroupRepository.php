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

    public function save(CustomerGroup $group): void;

    public function delete(CustomerGroup $group): void;
}
