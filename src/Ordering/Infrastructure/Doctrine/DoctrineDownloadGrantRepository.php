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

    public function findByToken(#[\SensitiveParameter] string $token): ?DownloadGrant
    {
        return $this->entityManager->getRepository(DownloadGrant::class)->findOneBy(['token' => $token]);
    }

    public function consume(DownloadGrant $grant, \DateTimeImmutable $at, string $by = '', int $until = 0): bool
    {
        // One statement, so two downloads at once cannot both take the last one.
        $changed = $this->entityManager->createQuery(
            'UPDATE ' . DownloadGrant::class . ' g SET g.downloads = g.downloads + 1, g.lastDownloadedAt = :at, g.lastDownloadedBy = :by, g.resumesLeft = :resumes, g.sentUntil = :until, g.remaining = CASE WHEN g.remaining IS NULL THEN g.remaining ELSE g.remaining - 1 END
             WHERE g.id = :id AND (g.remaining IS NULL OR g.remaining > 0)',
        )->setParameter('id', $grant->id())->setParameter('at', $at, 'datetime_immutable')->setParameter('by', $by)->setParameter('resumes', DownloadGrant::RESUMES)->setParameter('until', $until)->execute();
        $this->entityManager->refresh($grant);

        return $changed === 1;
    }

    public function resume(DownloadGrant $grant, string $by, int $least = \PHP_INT_MAX, int $until = 0): bool
    {
        // Where it was sent to is read in the statement: a resume asked for while the download is still going
        // finds it sent to its end. It only grows: a short range must not move it back towards the start.
        $changed = $this->entityManager->createQuery(
            'UPDATE ' . DownloadGrant::class . ' g SET g.resumesLeft = g.resumesLeft - 1, g.sentUntil = CASE WHEN g.sentUntil < :until THEN :until ELSE g.sentUntil END WHERE g.id = :id AND g.resumesLeft > 0 AND g.lastDownloadedBy = :by AND g.sentUntil <= :least',
        )->setParameter('id', $grant->id())->setParameter('by', $by)->setParameter('least', $least)->setParameter('until', $until)->execute();
        $this->entityManager->refresh($grant);

        return $changed === 1;
    }

    public function sentOnly(DownloadGrant $grant, int $until): void
    {
        $this->entityManager->createQuery('UPDATE ' . DownloadGrant::class . ' g SET g.sentUntil = :until WHERE g.id = :id AND g.sentUntil > :until')
            ->setParameter('id', $grant->id())->setParameter('until', $until)->execute();
        $this->entityManager->refresh($grant);
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
