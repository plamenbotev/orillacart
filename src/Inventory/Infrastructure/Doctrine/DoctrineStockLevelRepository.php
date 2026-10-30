<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use OrillaCart\Inventory\Domain\Stock;
use OrillaCart\Inventory\Domain\StockItem;
use OrillaCart\Inventory\Domain\StockItemKind;
use OrillaCart\Inventory\Domain\StockLevel;
use OrillaCart\Inventory\Domain\StockLevelRepository;
use OrillaCart\Inventory\Domain\Stockroom;
use OrillaCart\Shared\Persistence\Transactions;

final readonly class DoctrineStockLevelRepository implements StockLevelRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Transactions $transactions,
    ) {}

    public function stockOf(array $items): array
    {
        return $this->group($items, $this->load($items, false));
    }

    public function lockedStockOf(array $items, \Closure $work): mixed
    {
        return $this->transactions->run(fn(): mixed => $work($this->group($items, $this->load($items, true))));
    }

    public function atMost(int $quantity): array
    {
        /** @var list<StockLevel> */
        return $this->entityManager->createQueryBuilder()
            ->select('l', 's')
            ->from(StockLevel::class, 'l')
            ->join('l.stockroom', 's')
            ->where('l.quantity <= :quantity')
            ->andWhere('s.active = true')
            ->setParameter('quantity', $quantity)
            ->orderBy('l.id')
            ->getQuery()
            ->getResult();
    }

    public function totals(?int $stockroomId = null): array
    {
        $rows = $this->scoped($stockroomId)
            ->select('l.itemKind AS kind, l.itemId AS id, SUM(l.quantity) AS total')
            ->groupBy('l.itemKind, l.itemId')
            ->getQuery()
            ->getArrayResult();
        $totals = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !is_numeric($row['id'] ?? null) || !is_numeric($row['total'] ?? null)) {
                continue;
            }
            $kind = $row['kind'] instanceof StockItemKind ? $row['kind'] : StockItemKind::tryFrom(\is_string($row['kind']) ? $row['kind'] : '');
            if ($kind !== null) {
                $totals[(string) new StockItem($kind, (int) $row['id'])] = (int) $row['total'];
            }
        }

        return $totals;
    }

    public function itemsOwed(?int $stockroomId = null): array
    {
        return $this->items($this->scoped($stockroomId)->andWhere('l.quantity < 0'));
    }

    public function countedItems(): array
    {
        $counted = [];
        foreach ($this->entityManager->createQuery('SELECT DISTINCT l.itemKind AS kind, l.itemId AS id FROM ' . StockLevel::class . ' l')->getArrayResult() as $row) {
            if (!\is_array($row) || !is_numeric($row['id'] ?? null)) {
                continue;
            }
            $kind = $row['kind'] instanceof StockItemKind ? $row['kind'] : StockItemKind::tryFrom(\is_string($row['kind']) ? $row['kind'] : '');
            if ($kind !== null) {
                $counted[(string) new StockItem($kind, (int) $row['id'])] = true;
            }
        }

        return $counted;
    }

    public function itemsOnlyIn(int $stockroomId): int
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(l.id) FROM ' . StockLevel::class . ' l WHERE IDENTITY(l.stockroom) = :id AND NOT EXISTS ('
            . 'SELECT o.id FROM ' . StockLevel::class . ' o WHERE o.itemKind = l.itemKind AND o.itemId = l.itemId AND IDENTITY(o.stockroom) <> :id)',
        )->setParameter('id', $stockroomId)->getSingleScalarResult();
    }

    public function add(StockLevel $level): void
    {
        $this->entityManager->persist($level);
    }

    public function remove(StockLevel $level): void
    {
        $this->entityManager->remove($level);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function removeItems(array $items): void
    {
        if ($items === []) {
            return;
        }
        $query = $this->entityManager->createQueryBuilder()->delete(StockLevel::class, 'l');
        $this->matching($query, $items);
        $query->getQuery()->execute();
    }

    /**
     * @param list<StockItem> $items
     *
     * @return list<StockLevel>
     */
    private function load(array $items, bool $lock): array
    {
        if ($items === []) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(StockLevel::class, 'l');
        if (!$lock) {
            $query->addSelect('s')->join('l.stockroom', 's');
        }
        $this->matching($query, $items);
        $query = $query->getQuery();
        if ($lock) {
            // Read again under the lock: levels loaded earlier in the request (pricing the order) may be older than
            // the rows, and writing them back would sell what another order took. The levels alone are locked: a
            // joined stockroom would be locked with them (FOR UPDATE takes every row read), and every order would
            // then wait for every other one's.
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
            $query->setHint(Query::HINT_REFRESH, true);
        }

        /** @var list<StockLevel> $levels */
        $levels = $query->getResult();
        if ($lock && $levels !== []) {
            // Their stockrooms in one query, unlocked, in place of a query per level.
            $this->entityManager->createQueryBuilder()->select('s')->from(Stockroom::class, 's')
                ->where('s.id IN (:ids)')->setParameter('ids', array_values(array_unique(array_map(static fn(StockLevel $level): int => $level->stockroom()->id(), $levels))))
                ->getQuery()->getResult();
        }

        return $levels;
    }

    private function scoped(?int $stockroomId): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT l.itemKind AS kind, l.itemId AS id')
            ->from(StockLevel::class, 'l')
            ->join('l.stockroom', 's');

        return $stockroomId === null
            ? $query->where('s.active = true')
            : $query->where('s.id = :stockroom')->setParameter('stockroom', $stockroomId);
    }

    /** @return list<StockItem> */
    private function items(QueryBuilder $query): array
    {
        $items = [];
        foreach ($query->getQuery()->getArrayResult() as $row) {
            if (!\is_array($row) || !is_numeric($row['id'] ?? null)) {
                continue;
            }
            $kind = $row['kind'] instanceof StockItemKind ? $row['kind'] : StockItemKind::tryFrom(\is_string($row['kind']) ? $row['kind'] : '');
            if ($kind !== null) {
                $items[] = new StockItem($kind, (int) $row['id']);
            }
        }

        return $items;
    }

    /**
     * @param list<StockItem> $items
     */
    private function matching(QueryBuilder $query, array $items): void
    {
        $conditions = [];
        foreach (array_values($items) as $index => $item) {
            $conditions[] = "(l.itemKind = :kind{$index} AND l.itemId = :id{$index})";
            $query->setParameter("kind{$index}", $item->kind->value)->setParameter("id{$index}", $item->id);
        }
        $query->where(implode(' OR ', $conditions));
    }

    /**
     * @param list<StockItem>  $items
     * @param list<StockLevel> $levels
     *
     * @return list<Stock>
     */
    private function group(array $items, array $levels): array
    {
        return array_map(
            static fn(StockItem $item): Stock => new Stock($item, array_values(array_filter($levels, static fn(StockLevel $level): bool => $level->item()->equals($item)))),
            $items,
        );
    }
}
