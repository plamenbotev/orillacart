<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Customers\Domain\CustomerNote;
use OrillaCart\Customers\Domain\CustomerNoteRepository;

final readonly class DoctrineCustomerNoteRepository implements CustomerNoteRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function ofCustomer(int $customerId): array
    {
        return array_values($this->entityManager->getRepository(CustomerNote::class)->findBy(['customerId' => $customerId], ['createdAt' => 'DESC', 'id' => 'DESC']));
    }

    public function find(int $id): ?CustomerNote
    {
        return $this->entityManager->find(CustomerNote::class, $id);
    }

    public function save(CustomerNote $note): void
    {
        $this->entityManager->persist($note);
        $this->entityManager->flush();
    }

    public function remove(CustomerNote $note): void
    {
        $this->entityManager->remove($note);
        $this->entityManager->flush();
    }

    public function removeOfCustomer(int $customerId): void
    {
        $this->entityManager->createQueryBuilder()->delete(CustomerNote::class, 'n')
            ->where('n.customerId = :customer')->setParameter('customer', $customerId)
            ->getQuery()->execute();
    }
}
