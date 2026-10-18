<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Customers;

use OrillaCart\Customers\Domain\CustomerGroup;
use OrillaCart\Customers\Domain\CustomerGroupRepository;

final class InMemoryCustomerGroupRepository implements CustomerGroupRepository
{
    /** @var array<int, CustomerGroup> */
    public array $groups = [];
    private int $nextId = 1;

    public function all(): array
    {
        $groups = array_values($this->groups);
        usort($groups, static fn(CustomerGroup $a, CustomerGroup $b): int => $a->name() <=> $b->name());

        return $groups;
    }

    public function find(int $id): ?CustomerGroup
    {
        return $this->groups[$id] ?? null;
    }

    public function findByName(string $name): ?CustomerGroup
    {
        foreach ($this->groups as $group) {
            if (strcasecmp($group->name(), trim($name)) === 0) {
                return $group;
            }
        }

        return null;
    }

    public function groupIdsOf(int $customerId): array
    {
        return array_values(array_map(static fn(CustomerGroup $group): int => $group->id(), array_filter($this->groups, static fn(CustomerGroup $group): bool => $group->contains($customerId))));
    }

    public function save(CustomerGroup $group): void
    {
        $id = new \ReflectionProperty(CustomerGroup::class, 'id');
        if ($id->getValue($group) === null) {
            $id->setValue($group, $this->nextId++);
        }
        $this->groups[$group->id()] = $group;
    }

    public function delete(CustomerGroup $group): void
    {
        unset($this->groups[$group->id()]);
    }
}
