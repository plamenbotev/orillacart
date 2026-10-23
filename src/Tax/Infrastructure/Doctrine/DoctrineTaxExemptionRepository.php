<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Tax\Domain\TaxExemption;
use OrillaCart\Tax\Domain\TaxExemptionRepository;

final readonly class DoctrineTaxExemptionRepository implements TaxExemptionRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?TaxExemption
    {
        return $this->entityManager->find(TaxExemption::class, $id);
    }

    public function ofCustomer(int $customerId): array
    {
        /** @var list<TaxExemption> */
        return $this->entityManager->getRepository(TaxExemption::class)->findBy(['customerId' => $customerId], ['id' => 'DESC']);
    }

    public function save(TaxExemption $exemption): void
    {
        $this->entityManager->persist($exemption);
        $this->entityManager->flush();
    }

    public function remove(TaxExemption $exemption): void
    {
        $this->entityManager->remove($exemption);
        $this->entityManager->flush();
    }

    public function removeOfCustomer(int $customerId): void
    {
        $this->entityManager->createQueryBuilder()->delete(TaxExemption::class, 'e')
            ->where('e.customerId = :customer')
            ->setParameter('customer', $customerId)
            ->getQuery()
            ->execute();
    }
}
