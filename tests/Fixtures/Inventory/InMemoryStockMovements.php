<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Inventory;

use OrillaCart\Inventory\Domain\MovementSearch;
use OrillaCart\Inventory\Domain\StockItem;
use OrillaCart\Inventory\Domain\StockMovement;
use OrillaCart\Inventory\Domain\StockMovementRepository;

final class InMemoryStockMovements implements StockMovementRepository
{
    /** @var list<StockMovement> oldest first */
    public array $movements = [];
    private int $nextId = 1;

    public function add(array $movements): void
    {
        $id = new \ReflectionProperty(StockMovement::class, 'id');
        foreach ($movements as $movement) {
            $id->setValue($movement, $this->nextId++);
            $this->movements[] = $movement;
        }
    }

    public function search(MovementSearch $search, int $offset, int $limit): array
    {
        $found = array_reverse($this->matching($search));

        return [\array_slice($found, $offset, $limit), \count($found)];
    }

    public function totals(MovementSearch $search): array
    {
        $totals = [];
        foreach ($this->matching($search) as $movement) {
            $key = $movement->stockroom() . '|' . $movement->reason()->value;
            $totals[$key] ??= ['reason' => $movement->reason(), 'stockroomId' => $movement->stockroomId(), 'stockroom' => $movement->stockroom(), 'in' => 0, 'out' => 0];
            $totals[$key][$movement->delta() > 0 ? 'in' : 'out'] += abs($movement->delta());
        }
        ksort($totals);

        return array_values($totals);
    }

    /** @return list<StockMovement> */
    private function matching(MovementSearch $search): array
    {
        return array_values(array_filter($this->movements, static fn(StockMovement $movement): bool => ($search->items === [] || array_filter($search->items, static fn(StockItem $item): bool => $item->equals($movement->item())) !== [])
            && ($search->stockroomId === null || $search->stockroomId === $movement->stockroomId())
            && ($search->reason === null || $search->reason === $movement->reason())
            && ($search->orderId === null || $search->orderId === $movement->orderId())
            && ($search->from === null || $movement->at() >= $search->from)
            && ($search->before === null || $movement->at() < $search->before)));
    }
}
