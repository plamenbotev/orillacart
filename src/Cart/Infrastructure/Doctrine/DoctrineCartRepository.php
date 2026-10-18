<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Cart\Domain\Cart;
use OrillaCart\Cart\Domain\CartRepository;

final readonly class DoctrineCartRepository implements CartRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?Cart
    {
        return $this->entityManager->find(Cart::class, $id);
    }

    public function findByToken(string $token): ?Cart
    {
        return $this->entityManager->getRepository(Cart::class)->findOneBy(['tokenHash' => Cart::hash($token)]);
    }

    public function findByCustomer(int $customerId): ?Cart
    {
        return $this->entityManager->getRepository(Cart::class)->findOneBy(['customerId' => $customerId], ['updatedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function save(Cart $cart): void
    {
        $this->entityManager->persist($cart);
        $this->entityManager->flush();
    }

    public function removeOfCustomer(int $customerId): void
    {
        // Lines go with their cart (ON DELETE CASCADE).
        $this->entityManager->createQueryBuilder()
            ->delete(Cart::class, 'c')
            ->where('c.customerId = :customer')
            ->setParameter('customer', $customerId)
            ->getQuery()
            ->execute();
    }

    public function removeUnchangedSince(\DateTimeImmutable $before): int
    {
        // Lines go with their cart (ON DELETE CASCADE).
        $removed = $this->entityManager->createQueryBuilder()
            ->delete(Cart::class, 'c')
            ->where('c.updatedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();

        return \is_int($removed) ? $removed : 0;
    }
}
