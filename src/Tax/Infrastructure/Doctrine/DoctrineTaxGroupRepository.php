<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Tax\Domain\TaxGroup;
use OrillaCart\Tax\Domain\TaxGroupRepository;

final readonly class DoctrineTaxGroupRepository implements TaxGroupRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        /** @var list<TaxGroup> */
        return $this->entityManager->createQueryBuilder()
            ->select('g', 'r')
            ->from(TaxGroup::class, 'g')
            ->leftJoin('g.rates', 'r')
            ->orderBy('g.name')
            ->addOrderBy('g.id')
            ->getQuery()
            ->getResult();
    }

    public function find(int $id): ?TaxGroup
    {
        return $this->entityManager->find(TaxGroup::class, $id);
    }

    public function save(TaxGroup $group): void
    {
        $this->entityManager->persist($group);
        $this->entityManager->flush();
    }

    public function remove(TaxGroup $group): void
    {
        $this->entityManager->remove($group);
        $this->entityManager->flush();
    }
}
