<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Ordering\Domain\DownloadGrant;
use OrillaCart\Ordering\Domain\DownloadGrantRepository;
use OrillaCart\Ordering\Domain\OrderLine;
use OrillaCart\Ordering\Domain\OrderStatus;

final readonly class DoctrineDownloadGrantRepository implements DownloadGrantRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function findByToken(string $token): ?DownloadGrant
    {
        return $this->entityManager->getRepository(DownloadGrant::class)->findOneBy(['token' => $token]);
    }

    public function consume(DownloadGrant $grant): bool
    {
        // One statement, so two downloads at once cannot both take the last one.
        $changed = $this->entityManager->createQuery(
            'UPDATE ' . DownloadGrant::class . ' g SET g.downloads = g.downloads + 1, g.remaining = CASE WHEN g.remaining IS NULL THEN g.remaining ELSE g.remaining - 1 END
             WHERE g.id = :id AND (g.remaining IS NULL OR g.remaining > 0)',
        )->setParameter('id', $grant->id())->execute();
        $this->entityManager->refresh($grant);

        return $changed === 1;
    }

    public function ofCustomer(int $customerId): array
    {
        /** @var list<DownloadGrant> */
        return $this->entityManager->createQueryBuilder()
            ->select('g', 'o')
            ->from(DownloadGrant::class, 'g')
            ->join('g.order', 'o')
            ->where('o.customerId = :customer')
            ->andWhere('o.status IN (:paid)')
            ->setParameter('customer', $customerId)
            ->setParameter('paid', [OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Completed])
            ->orderBy('o.id', 'DESC')
            ->addOrderBy('g.linePosition')
            ->addOrderBy('g.id')
            ->getQuery()
            ->getResult();
    }

    public function isStorageKeyUsed(string $storageKey): bool
    {
        $grants = (int) $this->entityManager->createQuery('SELECT COUNT(g.id) FROM ' . DownloadGrant::class . ' g WHERE g.storageKey = :key')
            ->setParameter('key', $storageKey)
            ->getSingleScalarResult();
        if ($grants > 0) {
            return true;
        }
        // The key is 32 hexadecimal characters, so matching it in the lines' JSON cannot hit anything else.
        $lines = (int) $this->entityManager->createQuery('SELECT COUNT(l.id) FROM ' . OrderLine::class . ' l WHERE l.downloads LIKE :key')
            ->setParameter('key', '%"' . $storageKey . '"%')
            ->getSingleScalarResult();

        return $lines > 0;
    }
}
