<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use OrillaCart\Inventory\Domain\MovementSearch;
use OrillaCart\Inventory\Domain\StockItem;
use OrillaCart\Inventory\Domain\StockMovement;
use OrillaCart\Inventory\Domain\StockMovementRepository;
use OrillaCart\Inventory\Domain\StockReason;

final readonly class DoctrineStockMovements implements StockMovementRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function add(array $movements): void
    {
        foreach ($movements as $movement) {
            $this->entityManager->persist($movement);
        }
        $this->entityManager->flush();
    }

    public function search(MovementSearch $search, int $offset, int $limit): array
    {
        $query = $this->matching($search);
        $total = (int) (clone $query)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
        /** @var list<StockMovement> $movements */
        $movements = $query->select('m')
            ->orderBy('m.at', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [$movements, $total];
    }

    public function totals(MovementSearch $search): array
    {
        $rows = $this->matching($search)
            ->select('m.reason AS reason', 'm.stockroomId AS stockroomId', 'MAX(m.stockroom) AS stockroom')
            ->addSelect('SUM(CASE WHEN m.delta > 0 THEN m.delta ELSE 0 END) AS moved_in', 'SUM(CASE WHEN m.delta < 0 THEN -m.delta ELSE 0 END) AS moved_out')
            ->groupBy('m.reason', 'm.stockroomId')
            ->orderBy('stockroom')
            ->addOrderBy('m.reason')
            ->getQuery()
            ->getArrayResult();
        $totals = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $reason = $row['reason'] instanceof StockReason ? $row['reason'] : StockReason::tryFrom(\is_string($row['reason']) ? $row['reason'] : '');
            if ($reason === null) {
                continue;
            }
            $totals[] = [
                'reason' => $reason,
                'stockroomId' => is_numeric($row['stockroomId']) ? (int) $row['stockroomId'] : 0,
                'stockroom' => \is_string($row['stockroom']) ? $row['stockroom'] : '',
                'in' => is_numeric($row['moved_in']) ? (int) $row['moved_in'] : 0,
                'out' => is_numeric($row['moved_out']) ? (int) $row['moved_out'] : 0,
            ];
        }

        return $totals;
    }

    private function matching(MovementSearch $search): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()->from(StockMovement::class, 'm');
        if ($search->items !== []) {
            $or = [];
            foreach (array_values($search->items) as $index => $item) {
                \assert($item instanceof StockItem);
                $or[] = "(m.itemKind = :kind{$index} AND m.itemId = :id{$index})";
                $query->setParameter("kind{$index}", $item->kind)->setParameter("id{$index}", $item->id);
            }
            $query->andWhere(implode(' OR ', $or));
        }
        if ($search->stockroomId !== null) {
            $query->andWhere('m.stockroomId = :stockroom')->setParameter('stockroom', $search->stockroomId);
        }
        if ($search->reason !== null) {
            $query->andWhere('m.reason = :reason')->setParameter('reason', $search->reason);
        }
        if ($search->orderId !== null) {
            $query->andWhere('m.orderId = :order')->setParameter('order', $search->orderId);
        }
        if ($search->from !== null) {
            $query->andWhere('m.at >= :from')->setParameter('from', $search->from, 'datetime_immutable');
        }
        if ($search->before !== null) {
            $query->andWhere('m.at < :before')->setParameter('before', $search->before, 'datetime_immutable');
        }

        return $query;
    }
}
