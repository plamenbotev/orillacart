<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Shipping\Domain\ShippingClass;
use OrillaCart\Shipping\Domain\ShippingClassRepository;

final readonly class DoctrineShippingClassRepository implements ShippingClassRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        /** @var list<ShippingClass> */
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(ShippingClass::class, 'c')
            ->orderBy('c.name')
            ->addOrderBy('c.id')
            ->getQuery()
            ->getResult();
    }

    public function find(int $id): ?ShippingClass
    {
        return $this->entityManager->find(ShippingClass::class, $id);
    }

    public function isNameTaken(string $name, ?int $exceptId = null): bool
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(ShippingClass::class, 'c')
            ->where('c.name = :name')
            ->setParameter('name', trim($name));
        if ($exceptId !== null) {
            $query->andWhere('c.id != :id')->setParameter('id', $exceptId);
        }

        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }

    public function save(ShippingClass $class): void
    {
        $this->entityManager->persist($class);
        $this->entityManager->flush();
    }

    public function remove(ShippingClass $class): void
    {
        $this->entityManager->remove($class);
        $this->entityManager->flush();
    }
}
