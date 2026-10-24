<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Application\Dto\CustomerGroupView;
use OrillaCart\Customers\Application\Dto\SaveCustomerGroup;
use OrillaCart\Customers\Application\Dto\SetCustomerGroups;
use OrillaCart\Customers\Domain\CustomerGroup;
use OrillaCart\Customers\Domain\CustomerGroupNotFound;
use OrillaCart\Customers\Domain\CustomerGroupRepository;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Customers\Domain\CustomerRuleViolation;

/**
 * The customer groups the shop manages, and who is in them; price rules give groups their own prices.
 */
final readonly class CustomerGroups
{
    public function __construct(
        private CustomerGroupRepository $groups,
        private Accounts $accounts,
    ) {}

    /** @return list<CustomerGroupView> by name */
    public function groups(): array
    {
        $members = $this->groups->memberCounts();

        return array_map(static fn(CustomerGroup $group): CustomerGroupView => new CustomerGroupView($group->id(), $group->name(), $members[$group->id()] ?? 0), $this->groups->all());
    }

    /**
     * @throws CustomerRuleViolation for an empty name or one in use
     */
    public function create(SaveCustomerGroup $command): CustomerGroupView
    {
        $this->requireFreeName($command->name, null);
        $group = new CustomerGroup($command->name);
        $this->groups->save($group);

        return new CustomerGroupView($group->id(), $group->name(), 0);
    }

    /**
     * @throws CustomerGroupNotFound
     * @throws CustomerRuleViolation
     */
    public function rename(int $id, SaveCustomerGroup $command): CustomerGroupView
    {
        $group = $this->get($id);
        $this->requireFreeName($command->name, $id);
        $group->rename($command->name);
        $this->groups->save($group);

        return new CustomerGroupView($group->id(), $group->name(), \count($group->customerIds()));
    }

    /**
     * Its customers leave it; price rules for it no longer apply to anyone.
     *
     * @throws CustomerGroupNotFound
     */
    public function delete(int $id): void
    {
        $this->groups->delete($this->get($id));
    }

    /** @return list<int> */
    public function groupIdsOf(int $customerId): array
    {
        return $this->groups->groupIdsOf($customerId);
    }

    /**
     * @return list<int> the groups the customer is in now
     *
     * @throws CustomerNotFound
     * @throws CustomerGroupNotFound
     */
    public function setGroupsOf(int $customerId, SetCustomerGroups $command): array
    {
        if ($this->accounts->find($customerId) === null) {
            throw new CustomerNotFound($customerId);
        }
        $wanted = array_values(array_unique($command->groupIds));
        foreach ($wanted as $id) {
            $this->get($id);
        }
        foreach ($this->groups->all() as $group) {
            $in = \in_array($group->id(), $wanted, true);
            if ($in !== $group->contains($customerId)) {
                $in ? $group->add($customerId) : $group->remove($customerId);
                $this->groups->save($group);
            }
        }

        return $this->groups->groupIdsOf($customerId);
    }

    private function get(int $id): CustomerGroup
    {
        return $this->groups->find($id) ?? throw new CustomerGroupNotFound($id);
    }

    private function requireFreeName(string $name, ?int $id): void
    {
        $existing = $this->groups->findByName($name);
        if ($existing !== null && $existing->id() !== $id) {
            throw new CustomerRuleViolation('Another group is called %s.', 'orillacart_group_name_taken', values: [trim($name)]);
        }
    }
}
