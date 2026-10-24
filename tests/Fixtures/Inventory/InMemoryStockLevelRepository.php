<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Inventory;

use OrillaCart\Inventory\Domain\Stock;
use OrillaCart\Inventory\Domain\StockItem;
use OrillaCart\Inventory\Domain\StockLevel;
use OrillaCart\Inventory\Domain\StockLevelRepository;
use OrillaCart\Inventory\Domain\Stockroom;

final class InMemoryStockLevelRepository implements StockLevelRepository
{
    /** @var list<StockLevel> */
    private array $levels = [];

    public function stockOf(array $items): array
    {
        return array_map(
            fn(StockItem $item): Stock => new Stock($item, array_values(array_filter($this->levels, static fn(StockLevel $level): bool => $level->item()->equals($item)))),
            $items,
        );
    }

    public function lockedStockOf(array $items, \Closure $work): mixed
    {
        return $work($this->stockOf($items));
    }

    public function atMost(int $quantity): array
    {
        return array_values(array_filter($this->levels, static fn(StockLevel $level): bool => $level->quantity() <= $quantity && $level->stockroom()->isActive()));
    }

    public function totals(?int $stockroomId = null): array
    {
        $totals = [];
        foreach ($this->scoped($stockroomId) as $level) {
            $totals[(string) $level->item()] = ($totals[(string) $level->item()] ?? 0) + $level->quantity();
        }

        return $totals;
    }

    public function itemsOwed(?int $stockroomId = null): array
    {
        $owed = [];
        foreach ($this->scoped($stockroomId) as $level) {
            if ($level->quantity() < 0) {
                $owed[(string) $level->item()] = $level->item();
            }
        }

        return array_values($owed);
    }

    /** @return list<StockLevel> */
    private function scoped(?int $stockroomId): array
    {
        return array_values(array_filter($this->levels, static fn(StockLevel $level): bool => $stockroomId === null ? $level->stockroom()->isActive() : $level->stockroom()->id() === $stockroomId));
    }

    public function countedItems(): array
    {
        $counted = [];
        foreach ($this->levels as $level) {
            $counted[(string) $level->item()] = true;
        }

        return $counted;
    }

    public function itemsOnlyIn(int $stockroomId): int
    {
        $here = array_filter($this->levels, static fn(StockLevel $level): bool => $level->stockroom()->id() === $stockroomId);

        return \count(array_filter($here, fn(StockLevel $level): bool => array_filter($this->levels, static fn(StockLevel $other): bool => $other->item()->equals($level->item()) && $other->stockroom()->id() !== $stockroomId) === []));
    }

    public function add(StockLevel $level): void
    {
        $this->levels[] = $level;
    }

    public function remove(StockLevel $level): void
    {
        $this->levels = array_values(array_filter($this->levels, static fn(StockLevel $other): bool => $other !== $level));
    }

    public function flush(): void {}

    public function removeItems(array $items): void
    {
        $this->levels = array_values(array_filter(
            $this->levels,
            static fn(StockLevel $level): bool => array_filter($items, static fn(StockItem $item): bool => $level->item()->equals($item)) === [],
        ));
    }

    public function removeStockroom(Stockroom $stockroom): void
    {
        $this->levels = array_values(array_filter($this->levels, static fn(StockLevel $level): bool => $level->stockroom() !== $stockroom));
    }
}
