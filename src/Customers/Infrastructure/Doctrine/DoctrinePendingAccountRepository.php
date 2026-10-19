<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Customers\Domain\PendingAccount;
use OrillaCart\Customers\Domain\PendingAccountRepository;

final readonly class DoctrinePendingAccountRepository implements PendingAccountRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function findByToken(string $token): ?PendingAccount
    {
        return $this->entityManager->getRepository(PendingAccount::class)->findOneBy(['tokenHash' => PendingAccount::hash($token)]);
    }

    public function findByEmail(string $email): ?PendingAccount
    {
        return $this->entityManager->getRepository(PendingAccount::class)->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function save(PendingAccount $account): void
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();
    }

    public function remove(PendingAccount $account): void
    {
        $this->entityManager->remove($account);
        $this->entityManager->flush();
    }

    public function removeExpired(\DateTimeImmutable $now): void
    {
        $this->entityManager->createQueryBuilder()->delete(PendingAccount::class, 'p')
            ->where('p.expiresAt <= :now')->setParameter('now', $now)
            ->getQuery()->execute();
    }
}
