<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Returns\Domain\ItemReturn;
use OrillaCart\Returns\Domain\ReturnRepository;
use OrillaCart\Returns\Domain\ReturnStatus;

final readonly class DoctrineReturnRepository implements ReturnRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id, bool $lock = false): ?ItemReturn
    {
        $return = $this->entityManager->find(ItemReturn::class, $id);
        if ($return !== null && $lock) {
            // Read again under the lock: a copy loaded earlier in the request may be older than the row.
            $this->entityManager->refresh($return, LockMode::PESSIMISTIC_WRITE);
        }

        return $return;
    }

    public function orderIds(): array
    {
        // Returns still open: one refused or refunded is done with.
        $ids = $this->entityManager->createQueryBuilder()->select('DISTINCT r.orderId')->from(ItemReturn::class, 'r')
            ->where('r.status IN (:open)')->setParameter('open', array_filter(ReturnStatus::cases(), static fn(ReturnStatus $status): bool => $status->holdsItems()))
            ->getQuery()->getSingleColumnResult();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    public function forOrder(int $orderId): array
    {
        /** @var list<ItemReturn> */
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ItemReturn::class, 'r')
            ->where('r.orderId = :order')
            ->setParameter('order', $orderId)
            ->orderBy('r.requestedAt')
            ->addOrderBy('r.id')
            ->getQuery()
            ->getResult();
    }

    public function save(ItemReturn $return): void
    {
        $this->entityManager->persist($return);
        $this->entityManager->flush();
    }
}
