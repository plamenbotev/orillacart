<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Customers\Domain\PendingAccount;
use OrillaCart\Customers\Domain\PendingAccountRepository;

final readonly class DoctrinePendingAccountRepository implements PendingAccountRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function findByToken(#[\SensitiveParameter] string $token): ?PendingAccount
    {
        return $this->entityManager->getRepository(PendingAccount::class)->findOneBy(['tokenHash' => PendingAccount::hash($token)]);
    }

    public function findByEmail(string $email): ?PendingAccount
    {
        return $this->entityManager->getRepository(PendingAccount::class)->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function find(int $id): ?PendingAccount
    {
        return $this->entityManager->find(PendingAccount::class, $id);
    }

    public function invitations(): array
    {
        /** @var list<PendingAccount> */
        return $this->entityManager->createQueryBuilder()->select('p')->from(PendingAccount::class, 'p')
            ->where('p.invitedBy IS NOT NULL')->orderBy('p.createdAt', 'DESC')->addOrderBy('p.id', 'DESC')
            ->getQuery()->getResult();
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
            ->where('p.expiresAt <= :now AND (p.invitedBy IS NULL OR p.expiresAt <= :kept)')
            ->setParameter('now', $now)->setParameter('kept', $now->modify('-30 days'))
            ->getQuery()->execute();
    }
}
