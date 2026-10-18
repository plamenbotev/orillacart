<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application;

use OrillaCart\Inventory\Application\Dto\ChangeStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\StockView;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Domain\Event\StockChanged;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Inventory\Domain\InventoryRuleViolation;
use OrillaCart\Inventory\Domain\Stock;
use OrillaCart\Inventory\Domain\StockItem;
use OrillaCart\Inventory\Domain\StockLevel;
use OrillaCart\Inventory\Domain\StockLevelRepository;
use OrillaCart\Inventory\Domain\StockroomRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Stock levels of products, variations and options: setting them, asking how many can be sold,
 * and taking stock for orders.
 */
final readonly class StockService
{
    public function __construct(
        private StockLevelRepository $levels,
        private StockroomRepository $stockrooms,
        private EventDispatcherInterface $events,
    ) {}

    public function stock(StockItemRef $ref): StockView
    {
        return StockView::fromStock($this->levels->stockOf([$ref->toItem()])[0]);
    }

    /**
     * How many of each item each stockroom holds, for many items at once. A stockroom without a level of an
     * item is left out.
     *
     * @param list<StockItemRef> $refs
     *
     * @return array<string, array<int, int>> by item ("product:12"), then stockroom id
     */
    public function levelsOf(array $refs): array
    {
        $levels = [];
        foreach ($this->levels->stockOf(array_map(static fn(StockItemRef $ref): StockItem => $ref->toItem(), $refs)) as $stock) {
            $levels[(string) $stock->item] = [];
            foreach ($stock->levels() as $level) {
                $levels[(string) $stock->item][$level->stockroom()->id()] = $level->quantity();
            }
        }

        return $levels;
    }

    /**
     * Changes single levels of many items together, as the stock sheet saves them: a quantity sets the
     * stockroom's level, null removes it. Nothing changes when one change is refused.
     *
     * @param list<ChangeStockLevel> $changes
     *
     * @throws InventoryRuleViolation for a stockroom that does not exist, or a cell changed twice
     */
    public function changeLevels(array $changes): void
    {
        $byItem = [];
        foreach ($changes as $change) {
            $key = (string) $change->ref()->toItem();
            if (\array_key_exists($change->stockroomId, $byItem[$key] ?? [])) {
                throw new InventoryRuleViolation(\sprintf('The level of %s in stockroom %d is changed twice.', $key, $change->stockroomId), 'orillacart_duplicate_stockroom');
            }
            $byItem[$key][$change->stockroomId] = $change->quantity;
        }
        $stockrooms = [];
        foreach ($changes as $change) {
            $stockrooms[$change->stockroomId] ??= $this->stockrooms->find($change->stockroomId)
                ?? throw new InventoryRuleViolation(\sprintf('There is no stockroom %d.', $change->stockroomId), 'orillacart_unknown_stockroom');
        }

        $items = array_map(StockItem::fromString(...), array_keys($byItem));
        $before = self::availableOf($this->levels->stockOf($items));
        foreach ($this->levels->stockOf($items) as $stock) {
            $wanted = $byItem[(string) $stock->item];
            foreach ($stock->levels() as $level) {
                $stockroomId = $level->stockroom()->id();
                if (!\array_key_exists($stockroomId, $wanted)) {
                    continue;
                }
                if ($wanted[$stockroomId] === null) {
                    $this->levels->remove($level);
                } else {
                    $level->set($wanted[$stockroomId]);
                }
                unset($wanted[$stockroomId]);
            }
            foreach ($wanted as $stockroomId => $quantity) {
                if ($quantity !== null) {
                    $this->levels->add(new StockLevel($stockrooms[$stockroomId], $stock->item, $quantity));
                }
            }
        }
        $this->levels->flush();
        $this->announce($before, self::availableOf($this->levels->stockOf($items)));
    }

    /**
     * @throws InventoryRuleViolation for a stockroom that does not exist, or one listed twice
     */
    public function setLevels(StockItemRef $ref, SetStockLevels $command): StockView
    {
        $item = $ref->toItem();
        $wanted = [];
        foreach ($command->levels as $level) {
            if (isset($wanted[$level->stockroomId])) {
                throw new InventoryRuleViolation(\sprintf('Stockroom %d is listed twice.', $level->stockroomId), 'orillacart_duplicate_stockroom');
            }
            $wanted[$level->stockroomId] = $level->quantity;
        }

        $stock = $this->levels->stockOf([$item])[0];
        $before = self::availableOf([$stock]);
        foreach ($stock->levels() as $level) {
            $stockroomId = $level->stockroom()->id();
            if (\array_key_exists($stockroomId, $wanted)) {
                $level->set($wanted[$stockroomId]);
                unset($wanted[$stockroomId]);
            } else {
                $this->levels->remove($level);
            }
        }
        foreach ($wanted as $stockroomId => $quantity) {
            $stockroom = $this->stockrooms->find($stockroomId)
                ?? throw new InventoryRuleViolation(\sprintf('There is no stockroom %d.', $stockroomId), 'orillacart_unknown_stockroom');
            $this->levels->add(new StockLevel($stockroom, $item, $quantity));
        }
        $this->levels->flush();
        $this->announce($before, self::availableOf($this->levels->stockOf([$item])));

        return $this->stock($ref);
    }

    /**
     * How many of the items can be sold together, such as a product with the options the customer chose:
     * the smallest number any of them allows.
     *
     * @param list<StockItemRef> $refs
     *
     * @return int|null null when stock limits none of them
     */
    public function available(array $refs): ?int
    {
        $available = null;
        foreach ($this->levels->stockOf(array_map(static fn(StockItemRef $ref): StockItem => $ref->toItem(), $refs)) as $stock) {
            $count = $stock->available();
            if ($count !== null) {
                $available = $available === null ? $count : min($available, $count);
            }
        }

        return $available === null ? null : max(0, $available);
    }

    /**
     * Takes the quantity of each item for a placed order: all of it or, when one item falls short, nothing. With
     * backorders, what is not in stock is owed instead (see Stock::take()).
     *
     * @param list<StockItemRef> $refs
     *
     * @return list<TakenStock>
     *
     * @throws InsufficientStock
     */
    public function take(array $refs, int $quantity, bool $backorders = false): array
    {
        $items = array_map(static fn(StockItemRef $ref): StockItem => $ref->toItem(), $refs);

        $before = [];
        $after = [];
        $taken = $this->levels->lockedStockOf($items, static function (array $stocks) use ($quantity, $backorders, &$before, &$after): array {
            /** @var list<Stock> $stocks */
            $before = self::availableOf($stocks);
            foreach ($stocks as $stock) {
                $available = $stock->available();
                if (!$backorders && $available !== null && $available < $quantity) {
                    throw new InsufficientStock($stock->item, $quantity, max(0, $available));
                }
            }
            $taken = [];
            foreach ($stocks as $stock) {
                $owed = max(0, $quantity - max(0, $stock->available() ?? $quantity));
                $owing = $stock->owingStockroomId();
                foreach ($stock->take($quantity, $backorders) as $stockroomId => $amount) {
                    $taken[] = new TakenStock((string) $stock->item, $stockroomId, $amount, $stockroomId === $owing ? $owed : 0);
                }
            }
            $after = self::availableOf($stocks);

            return $taken;
        });
        $this->announce($before, $after);

        return $taken;
    }

    /**
     * Puts back stock taken for an order, as when the order is cancelled. Stock of a stockroom that was deleted
     * since, or whose level of the item was removed, is put back as a new level when the stockroom still exists.
     *
     * @param list<TakenStock> $taken
     */
    public function giveBack(array $taken): void
    {
        $items = [];
        foreach ($taken as $part) {
            $items[$part->item] = StockItem::fromString($part->item);
        }
        if ($items === []) {
            return;
        }
        $before = [];
        $this->levels->lockedStockOf(array_values($items), function (array $stocks) use ($taken, &$before): void {
            /** @var list<Stock> $stocks */
            $before = self::availableOf($stocks);
            $byItem = [];
            foreach ($stocks as $stock) {
                $byItem[(string) $stock->item] = $stock;
            }
            foreach ($taken as $part) {
                $stock = $byItem[$part->item];
                if (!$stock->putBack($part->stockroomId, $part->quantity)) {
                    $stockroom = $this->stockrooms->find($part->stockroomId);
                    if ($stockroom !== null) {
                        $this->levels->add(new StockLevel($stockroom, $stock->item, $part->quantity));
                    }
                }
            }
        });
        // Levels made anew are not in the locked copies: ask again.
        $this->announce($before, self::availableOf($this->levels->stockOf(array_values($items))));
    }

    /**
     * @param list<Stock> $stocks
     *
     * @return array<string, ?int> how many can be sold, by item
     */
    private static function availableOf(array $stocks): array
    {
        $available = [];
        foreach ($stocks as $stock) {
            $available[(string) $stock->item] = $stock->available();
        }

        return $available;
    }

    /**
     * @param array<string, ?int> $before
     * @param array<string, ?int> $after
     */
    private function announce(array $before, array $after): void
    {
        foreach ($after as $item => $available) {
            $this->events->dispatch(new StockChanged((string) $item, $before[$item] ?? null, $available));
        }
    }

    /**
     * Forgets the stock of items the catalog no longer has.
     *
     * @param list<StockItemRef> $refs
     */
    public function removeItems(array $refs): void
    {
        if ($refs !== []) {
            $this->levels->removeItems(array_map(static fn(StockItemRef $ref): StockItem => $ref->toItem(), $refs));
        }
    }
}
