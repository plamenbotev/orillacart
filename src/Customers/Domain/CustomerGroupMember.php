<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customer_group_members')]
#[ORM\UniqueConstraint(name: 'customer_group_member', columns: ['group_id', 'customer_id'])]
#[ORM\Index(name: 'customer_groups_of', columns: ['customer_id'])]
class CustomerGroupMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: CustomerGroup::class, inversedBy: 'members')]
        #[ORM\JoinColumn(name: 'group_id', nullable: false, onDelete: 'CASCADE')]
        private CustomerGroup $group,
        #[ORM\Column]
        private int $customerId,
    ) {}

    public function group(): CustomerGroup
    {
        return $this->group;
    }

    public function customerId(): int
    {
        return $this->customerId;
    }
}
