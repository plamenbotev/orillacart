<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Payment\Domain\NotifiedEvent;
use OrillaCart\Payment\Domain\NotifiedEvents;

final readonly class DoctrineNotifiedEvents implements NotifiedEvents
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function wasHandled(string $gateway, string $eventId): bool
    {
        return $this->entityManager->find(NotifiedEvent::class, ['gateway' => $gateway, 'eventId' => $eventId]) !== null;
    }

    public function remember(NotifiedEvent $event): void
    {
        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }

    public function forgetBefore(\DateTimeImmutable $before): void
    {
        $this->entityManager->createQueryBuilder()->delete(NotifiedEvent::class, 'e')
            ->where('e.receivedAt < :before')->setParameter('before', $before)
            ->getQuery()->execute();
    }
}
