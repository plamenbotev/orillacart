<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Search\Domain\SearchFilter;
use OrillaCart\Search\Domain\SearchFilterRepository;

final readonly class DoctrineSearchFilterRepository implements SearchFilterRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        /** @var list<SearchFilter> */
        return $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(SearchFilter::class, 'f')
            ->orderBy('f.position')
            ->addOrderBy('f.label')
            ->getQuery()
            ->getResult();
    }

    public function find(int $id): ?SearchFilter
    {
        return $this->entityManager->find(SearchFilter::class, $id);
    }

    public function isKeyTaken(string $key, ?int $exceptId = null): bool
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->from(SearchFilter::class, 'f')
            ->where('f.filterKey = :key')
            ->setParameter('key', $key);
        if ($exceptId !== null) {
            $query->andWhere('f.id <> :id')->setParameter('id', $exceptId);
        }

        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }

    public function save(SearchFilter $filter): void
    {
        $this->entityManager->persist($filter);
        $this->entityManager->flush();
    }

    public function remove(SearchFilter $filter): void
    {
        $this->entityManager->remove($filter);
        $this->entityManager->flush();
    }
}
