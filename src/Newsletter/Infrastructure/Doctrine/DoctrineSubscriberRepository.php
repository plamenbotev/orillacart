<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Newsletter\Domain\Subscriber;
use OrillaCart\Newsletter\Domain\SubscriberRepository;

final readonly class DoctrineSubscriberRepository implements SubscriberRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function findByEmail(string $email): ?Subscriber
    {
        return $this->entityManager->getRepository(Subscriber::class)->findOneBy(['email' => Subscriber::normalize($email)]);
    }

    public function countSubscribed(): int
    {
        return (int) $this->entityManager->createQueryBuilder()->select('COUNT(s.id)')->from(Subscriber::class, 's')
            ->where('s.unsubscribedAt IS NULL')->getQuery()->getSingleScalarResult();
    }

    public function subscribed(int $afterId, int $limit): array
    {
        /** @var list<Subscriber> */
        return $this->entityManager->createQueryBuilder()->select('s')->from(Subscriber::class, 's')
            ->where('s.unsubscribedAt IS NULL')->andWhere('s.id > :after')->setParameter('after', $afterId)
            ->orderBy('s.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }

    public function save(Subscriber $subscriber): void
    {
        $this->entityManager->persist($subscriber);
        $this->entityManager->flush();
    }

    public function remove(Subscriber $subscriber): void
    {
        $this->entityManager->remove($subscriber);
        $this->entityManager->flush();
    }
}
