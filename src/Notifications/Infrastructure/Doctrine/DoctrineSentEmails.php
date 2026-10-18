<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Notifications\Domain\SentEmail;
use OrillaCart\Notifications\Domain\SentEmails;

final readonly class DoctrineSentEmails implements SentEmails
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function wasSent(string $job): bool
    {
        return $this->entityManager->find(SentEmail::class, $job) !== null;
    }

    public function remember(SentEmail $sent): void
    {
        $this->entityManager->persist($sent);
        $this->entityManager->flush();
    }

    public function forgetBefore(\DateTimeImmutable $before): int
    {
        $removed = $this->entityManager->createQueryBuilder()
            ->delete(SentEmail::class, 's')
            ->where('s.sentAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();

        return \is_int($removed) ? $removed : 0;
    }
}
