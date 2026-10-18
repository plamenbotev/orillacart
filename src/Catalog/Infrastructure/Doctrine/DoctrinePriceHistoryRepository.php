<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Catalog\Domain\PriceHistoryRepository;
use OrillaCart\Catalog\Domain\PricePoint;

final readonly class DoctrinePriceHistoryRepository implements PriceHistoryRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function latest(array $items): array
    {
        if ($items === []) {
            return [];
        }
        /** @var list<PricePoint> $points */
        $points = $this->entityManager->createQuery('SELECT p FROM ' . PricePoint::class . ' p WHERE p.item IN (:items) AND p.recordedAt = (SELECT MAX(q.recordedAt) FROM ' . PricePoint::class . ' q WHERE q.item = p.item) ORDER BY p.id')
            ->setParameter('items', $items)
            ->getResult();
        $latest = [];
        foreach ($points as $point) {
            $latest[$point->item()] = $point;
        }

        return $latest;
    }

    public function since(array $items, \DateTimeImmutable $since): array
    {
        if ($items === []) {
            return [];
        }
        /** @var list<PricePoint> */
        return $this->entityManager->createQuery('SELECT p FROM ' . PricePoint::class . ' p WHERE p.item IN (:items) AND (p.recordedAt >= :since OR p.recordedAt = (SELECT MAX(q.recordedAt) FROM ' . PricePoint::class . ' q WHERE q.item = p.item AND q.recordedAt < :since)) ORDER BY p.item, p.recordedAt, p.id')
            ->setParameter('items', $items)
            ->setParameter('since', $since)
            ->getResult();
    }

    public function add(PricePoint $point): void
    {
        $this->entityManager->persist($point);
        $this->entityManager->flush();
    }
}
