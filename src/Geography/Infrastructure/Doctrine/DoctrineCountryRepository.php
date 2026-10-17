<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Geography\Domain\Country;
use OrillaCart\Geography\Domain\CountryRepository;

final readonly class DoctrineCountryRepository implements CountryRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        /** @var list<Country> */
        return $this->entityManager->createQueryBuilder()
            ->select('c', 's')
            ->from(Country::class, 'c')
            ->leftJoin('c.states', 's')
            ->orderBy('c.name')
            ->addOrderBy('s.name')
            ->getQuery()
            ->getResult();
    }

    public function find(string $code): ?Country
    {
        return $this->entityManager->find(Country::class, $code);
    }
}
