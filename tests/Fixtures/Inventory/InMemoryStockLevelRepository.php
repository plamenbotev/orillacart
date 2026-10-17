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
