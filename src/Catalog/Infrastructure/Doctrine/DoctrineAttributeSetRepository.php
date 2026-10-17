<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\AttributeSetRepository;

final readonly class DoctrineAttributeSetRepository implements AttributeSetRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        /** @var list<AttributeSet> */
        return $this->entityManager->createQueryBuilder()
            ->select('s', 'a', 'o')
            ->from(AttributeSet::class, 's')
            ->leftJoin('s.attributes', 'a')
            ->leftJoin('a.options', 'o')
            ->orderBy('s.name')
            ->addOrderBy('s.id')
            ->getQuery()
            ->getResult();
    }

    public function find(int $id): ?AttributeSet
    {
        return $this->entityManager->find(AttributeSet::class, $id);
    }

    public function save(AttributeSet $set): void
    {
        $this->entityManager->persist($set);
        $this->entityManager->flush();
    }

    public function remove(AttributeSet $set): void
    {
        $this->entityManager->remove($set);
        $this->entityManager->flush();
    }
}
