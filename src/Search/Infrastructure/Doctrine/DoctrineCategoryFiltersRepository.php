<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Search\Domain\CategoryFilters;
use OrillaCart\Search\Domain\CategoryFiltersRepository;

final readonly class DoctrineCategoryFiltersRepository implements CategoryFiltersRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function ofCategories(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }
        /** @var list<CategoryFilters> $found */
        $found = $this->entityManager->getRepository(CategoryFilters::class)->findBy(['categoryId' => $categoryIds]);
        $byCategory = [];
        foreach ($found as $filters) {
            $byCategory[$filters->categoryId()] = $filters;
        }

        return $byCategory;
    }

    public function save(CategoryFilters $filters): void
    {
        $this->entityManager->persist($filters);
        $this->entityManager->flush();
    }

    public function remove(int $categoryId): void
    {
        $filters = $this->entityManager->find(CategoryFilters::class, $categoryId);
        if ($filters !== null) {
            $this->entityManager->remove($filters);
            $this->entityManager->flush();
        }
    }
}
