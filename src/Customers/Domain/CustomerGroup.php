<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Customers the shop treats alike, such as Wholesale or VIP: price rules give a group its own prices. A customer
 * can be in several groups.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_groups')]
#[ORM\UniqueConstraint(name: 'customer_group_name', columns: ['name'])]
class CustomerGroup
{
    public const int MAX_NAME_LENGTH = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    /** @var Collection<int, CustomerGroupMember> */
    #[ORM\OneToMany(targetEntity: CustomerGroupMember::class, mappedBy: 'group', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $members;

    public function __construct(string $name)
    {
        $this->members = new ArrayCollection();
        $this->rename($name);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The group has no id until it is saved.');
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @throws CustomerRuleViolation for an empty or too long name
     */
    public function rename(string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new CustomerRuleViolation(\sprintf('A group has a name of up to %d characters.', self::MAX_NAME_LENGTH), 'orillacart_invalid_group_name');
        }
        $this->name = $name;
    }

    /** @return list<int> */
    public function customerIds(): array
    {
        return array_values(array_map(static fn(CustomerGroupMember $member): int => $member->customerId(), $this->members->toArray()));
    }

    public function contains(int $customerId): bool
    {
        return \in_array($customerId, $this->customerIds(), true);
    }

    public function add(int $customerId): void
    {
        if (!$this->contains($customerId)) {
            $this->members->add(new CustomerGroupMember($this, $customerId));
        }
    }

    public function remove(int $customerId): void
    {
        foreach ($this->members as $member) {
            if ($member->customerId() === $customerId) {
                $this->members->removeElement($member);
            }
        }
    }
}
