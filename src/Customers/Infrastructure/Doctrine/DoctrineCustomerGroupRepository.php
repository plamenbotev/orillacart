<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Customers\Domain\CustomerGroup;
use OrillaCart\Customers\Domain\CustomerGroupMember;
use OrillaCart\Customers\Domain\CustomerGroupRepository;

final readonly class DoctrineCustomerGroupRepository implements CustomerGroupRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        /** @var list<CustomerGroup> */
        return $this->entityManager->getRepository(CustomerGroup::class)->findBy([], ['name' => 'ASC']);
    }

    public function find(int $id): ?CustomerGroup
    {
        return $this->entityManager->find(CustomerGroup::class, $id);
    }

    public function findByName(string $name): ?CustomerGroup
    {
        return $this->entityManager->getRepository(CustomerGroup::class)->findOneBy(['name' => trim($name)]);
    }

    public function groupIdsOfMany(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }
        $rows = $this->entityManager->createQueryBuilder()->select('m.customerId AS customer, IDENTITY(m.group) AS id')->from(CustomerGroupMember::class, 'm')
            ->where('m.customerId IN (:customers)')->setParameter('customers', $customerIds)
            ->getQuery()->getScalarResult();
        $groups = [];
        foreach ($rows as $row) {
            if (\is_array($row) && is_numeric($row['customer'] ?? null) && is_numeric($row['id'] ?? null)) {
                $groups[(int) $row['customer']][] = (int) $row['id'];
            }
        }

        return $groups;
    }

    public function memberCounts(): array
    {
        $rows = $this->entityManager->createQueryBuilder()->select('IDENTITY(m.group) AS id, COUNT(m.customerId) AS members')->from(CustomerGroupMember::class, 'm')
            ->groupBy('m.group')->getQuery()->getScalarResult();
        $counts = [];
        foreach ($rows as $row) {
            if (\is_array($row) && is_numeric($row['id'] ?? null) && is_numeric($row['members'] ?? null)) {
                $counts[(int) $row['id']] = (int) $row['members'];
            }
        }

        return $counts;
    }

    public function groupIdsOf(int $customerId): array
    {
        $rows = $this->entityManager->createQueryBuilder()->select('IDENTITY(m.group) AS id')->from(CustomerGroupMember::class, 'm')
            ->where('m.customerId = :customer')->setParameter('customer', $customerId)
            ->getQuery()->getScalarResult();

        $ids = [];
        foreach ($rows as $row) {
            if (\is_array($row) && is_numeric($row['id'] ?? null)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    public function save(CustomerGroup $group): void
    {
        $this->entityManager->persist($group);
        $this->entityManager->flush();
    }

    public function delete(CustomerGroup $group): void
    {
        $this->entityManager->remove($group);
        $this->entityManager->flush();
    }
}
