<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application;

use OrillaCart\Inventory\Application\Dto\ChangeStockLevel;
use OrillaCart\Inventory\Application\Dto\ItemLevelView;
use OrillaCart\Inventory\Application\Dto\MovementView;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\StockNote;
use OrillaCart\Inventory\Application\Dto\StockView;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Domain\Event\StockChanged;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Inventory\Domain\InventoryRuleViolation;
use OrillaCart\Inventory\Domain\MovementSearch;
use OrillaCart\Inventory\Domain\Stock;
use OrillaCart\Inventory\Domain\StockItem;
use OrillaCart\Inventory\Domain\StockLevel;
use OrillaCart\Inventory\Domain\StockLevelRepository;
use OrillaCart\Inventory\Domain\StockMovement;
use OrillaCart\Inventory\Domain\StockMovementRepository;
use OrillaCart\Inventory\Domain\StockReason;
use OrillaCart\Inventory\Domain\Stockroom;
use OrillaCart\Inventory\Domain\StockroomRepository;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Stock levels of products, variations and options: setting them, asking how many can be sold,
 * and taking stock for orders. Every move of a level is kept as a movement (the stock history), with why: the
 * shop counting, an import, a bulk edit, or an order. Stock taken for an order is recorded once the order has its
 * number (record()), in the same transaction.
 */
final readonly class StockService
{
    public function __construct(
        private StockLevelRepository $levels,
        private StockroomRepository $stockrooms,
        private EventDispatcherInterface $events,
        private ?StockMovementRepository $movements = null,
        private ?ClockInterface $clock = null,
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
     * The stock levels of active stockrooms holding this many of an item or fewer, owed stock below zero.
     *
     * @return list<ItemLevelView>
     */
    public function atMost(int $quantity): array
    {
        return array_map(
            static fn(StockLevel $level): ItemLevelView => new ItemLevelView(new StockItemRef($level->item()->kind->value, $level->item()->id), $level->stockroom()->id(), $level->quantity()),
            $this->levels->atMost($quantity),
        );
    }

    /**
     * How many of each item active stockrooms, or the one given, hold in all: below zero when they owe it.
     *
     * @return array<string, int> by item ("variation:12"); items never stocked are left out
     */
    public function totals(?int $stockroomId = null): array
    {
        return $this->levels->totals($stockroomId);
    }

    /**
     * The items a stockroom, active or not, has a level of; the others are not limited by stock.
     *
     * @return array<string, true> by item ("variation:12")
     */
    public function counted(): array
    {
        return $this->levels->countedItems();
    }

    /**
     * The items sold below zero, by an active stockroom or the one given: owed to customers who ordered them.
     *
     * @return list<StockItemRef>
     */
    public function itemsOwed(?int $stockroomId = null): array
    {
        return array_map(static fn(StockItem $item): StockItemRef => new StockItemRef($item->kind->value, $item->id), $this->levels->itemsOwed($stockroomId));
    }

    /**
     * A page of the stock history, newest first, and how many movements match in all.
     *
     * @return array{list<MovementView>, int}
     */
    public function movements(MovementSearch $search, int $offset, int $limit): array
    {
        if ($this->movements === null) {
            return [[], 0];
        }
        [$found, $total] = $this->movements->search($search, $offset, $limit);

        return [array_map(MovementView::fromMovement(...), $found), $total];
    }

    /**
     * What moved in all, in and out, by stockroom and reason.
     *
     * @return list<array{reason: string, stockroomId: int, stockroom: string, in: int, out: int}>
     */
    public function movedTotals(MovementSearch $search): array
    {
        return $this->movements === null ? [] : array_map(static fn(array $total): array => ['reason' => $total['reason']->value] + $total, $this->movements->totals($search));
    }

    /**
     * Changes single levels of many items together, as the stock sheet saves them: a quantity sets the
     * stockroom's level, null removes it. Nothing changes when one change is refused.
     *
     * @param list<ChangeStockLevel> $changes
     *
     * @throws InventoryRuleViolation for a stockroom that does not exist, or a cell changed twice
     */
    public function changeLevels(array $changes, StockNote $note = new StockNote()): void
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
        $stocks = $this->levels->stockOf($items);
        $before = self::availableOf($stocks);
        $was = self::levelsBy($stocks);
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
        $this->log($was, $items, $note);
        $this->announce($before, self::availableOf($this->levels->stockOf($items)));
    }

    /**
     * @throws InventoryRuleViolation for a stockroom that does not exist, or one listed twice
     */
    public function setLevels(StockItemRef $ref, SetStockLevels $command, StockNote $note = new StockNote()): StockView
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
        $was = self::levelsBy([$stock]);
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
        $this->log($was, [$item], $note);
        $this->announce($before, self::availableOf($this->levels->stockOf([$item])));

        return $this->stock($ref);
    }

    /**
     * Items whose stock is counted but that no stockroom holds yet start at 0 in the active stockroom that
     * delivers fastest (the one that would owe their backorders): counting starts from nothing, not without limit
     * (an item with no level at all is not limited), and the other stockrooms' lists stay as they were. Items that
     * have a level anywhere, and shops with no active stockroom, are left as they are.
     *
     * @param list<StockItemRef> $refs
     */
    public function countFromNothing(array $refs): void
    {
        $active = array_values(array_filter($this->stockrooms->all(), static fn(Stockroom $stockroom): bool => $stockroom->isActive()));
        if ($refs === [] || $active === []) {
            return;
        }
        $stocks = array_values(array_filter($this->levels->stockOf(array_map(static fn(StockItemRef $ref): StockItem => $ref->toItem(), $refs)), static fn(Stock $stock): bool => !$stock->isLimited()));
        if ($stocks === []) {
            return;
        }
        usort($active, static fn(Stockroom $a, Stockroom $b): int => [$a->maxDeliveryDays(), $a->id()] <=> [$b->maxDeliveryDays(), $b->id()]);
        $before = self::availableOf($stocks);
        foreach ($stocks as $stock) {
            $this->levels->add(new StockLevel($active[0], $stock->item, 0));
        }
        $this->levels->flush();
        $this->announce($before, self::availableOf($this->levels->stockOf(array_map(static fn(Stock $stock): StockItem => $stock->item, $stocks))));
    }

    /**
     * How many of each item can be sold, for many items at once: one query, not one each.
     *
     * @param list<StockItemRef> $refs
     *
     * @return array<string, ?int> by item ("variation:12"); null when stock does not limit it
     */
    public function availableEach(array $refs, bool $owedBelowZero = false): array
    {
        $available = [];
        foreach ($this->levels->stockOf(array_map(static fn(StockItemRef $ref): StockItem => $ref->toItem(), $refs)) as $stock) {
            $count = $stock->available();
            $available[(string) $stock->item] = $count === null || $owedBelowZero ? $count : max(0, $count);
        }

        return $available;
    }

    /**
     * Whether each item could be owed on backorder: stock does not limit it, or an active stockroom has a level of
     * it to owe from. An item only stockrooms that are off hold cannot be.
     *
     * @param list<StockItemRef> $refs
     *
     * @return array<string, bool> by item, as "product:12"
     */
    public function owableEach(array $refs): array
    {
        $owable = [];
        foreach ($this->levels->stockOf(array_map(static fn(StockItemRef $ref): StockItem => $ref->toItem(), $refs)) as $stock) {
            $owable[(string) $stock->item] = !$stock->isLimited() || $stock->canOwe();
        }

        return $owable;
    }

    /**
     * How many of the items can be sold together, such as a product with the options the customer chose:
     * the smallest number any of them allows.
     *
     * @param list<StockItemRef> $refs
     *
     * @return int|null null when stock limits none of them
     */
    public function available(array $refs, bool $owedBelowZero = false): ?int
    {
        $available = null;
        foreach ($this->levels->stockOf(array_map(static fn(StockItemRef $ref): StockItem => $ref->toItem(), $refs)) as $stock) {
            $count = $stock->available();
            if ($count !== null) {
                $available = $available === null ? $count : min($available, $count);
            }
        }

        return $available === null || $owedBelowZero ? $available : max(0, $available);
    }

    /**
     * Locks the stock of all the items an order is about to take, in one statement, until the transaction ends.
     * An order that takes its items one by one then never waits for another order that locked them in another
     * order: two such orders would wait for each other forever (a deadlock). Run it in the order's transaction.
     *
     * @param list<StockItemRef> $refs
     */
    public function lock(array $refs): void
    {
        $items = [];
        foreach ($refs as $ref) {
            $item = $ref->toItem();
            $items[(string) $item] = $item;
        }
        if ($items === []) {
            return;
        }
        ksort($items);
        $this->levels->lockedStockOf(array_values($items), static fn(): null => null);
    }

    /**
     * Takes the quantity of each item for a placed order: all of it or, when one item falls short, nothing. With
     * backorders, what is not in stock is owed instead (see Stock::take()), up to the limit of what may be owed.
     *
     * @param list<StockItemRef> $refs
     * @param int|null           $backorderLimit how many may be owed in all, what is owed already included; null for no limit
     *
     * @return list<TakenStock>
     *
     * @throws InsufficientStock
     */
    public function take(array $refs, int $quantity, bool $backorders = false, ?int $backorderLimit = null): array
    {
        $items = array_map(static fn(StockItemRef $ref): StockItem => $ref->toItem(), $refs);

        $before = [];
        $after = [];
        $taken = $this->levels->lockedStockOf($items, static function (array $stocks) use ($quantity, $backorders, $backorderLimit, &$before, &$after): array {
            /** @var list<Stock> $stocks */
            $before = self::availableOf($stocks);
            foreach ($stocks as $stock) {
                $available = $stock->available();
                $owable = $backorders ? $backorderLimit : 0;
                if ($available !== null && $owable !== null && $available + $owable < $quantity) {
                    throw new InsufficientStock($stock->item, $quantity, max(0, $available + $owable));
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
     * Takes again what an order gave back when it closed unpaid, as when its money comes late: for each line,
     * the quantity of each item, from the fastest stockrooms, never into backorders. All of it, or nothing when
     * one item falls short. The items are locked in one go.
     *
     * @param array<int, array<string, int>> $wanted by line, then by item ("product:12")
     *
     * @return array<int, list<TakenStock>> by line
     *
     * @throws InsufficientStock
     */
    public function takeAgain(array $wanted, StockNote $note = new StockNote(StockReason::Sale)): array
    {
        $items = [];
        $needed = [];
        foreach ($wanted as $line) {
            foreach ($line as $item => $quantity) {
                $items[$item] = StockItem::fromString($item);
                $needed[$item] = ($needed[$item] ?? 0) + $quantity;
            }
        }
        if ($items === []) {
            return array_map(static fn(): array => [], $wanted);
        }
        $before = [];
        $after = [];
        $taken = $this->levels->lockedStockOf(array_values($items), static function (array $stocks) use ($wanted, $needed, &$before, &$after): array {
            /** @var list<Stock> $stocks */
            $before = self::availableOf($stocks);
            $byItem = [];
            foreach ($stocks as $stock) {
                $available = $stock->available();
                $quantity = $needed[(string) $stock->item] ?? 0;
                if ($available !== null && $available < $quantity) {
                    throw new InsufficientStock($stock->item, $quantity, max(0, $available));
                }
                $byItem[(string) $stock->item] = $stock;
            }
            $taken = [];
            foreach ($wanted as $index => $line) {
                $taken[$index] = [];
                foreach ($line as $item => $quantity) {
                    foreach ($byItem[$item]->take($quantity, false) as $stockroomId => $amount) {
                        $taken[$index][] = new TakenStock($item, $stockroomId, $amount);
                    }
                }
            }
            $after = self::availableOf($stocks);

            return $taken;
        });
        $this->record(array_merge(...array_values($taken)), $note);
        $this->announce($before, $after);

        return $taken;
    }

    /**
     * Keeps stock taken for an order in the history, once the order has its number: take() runs while the
     * order is priced, before it is placed.
     *
     * @param list<TakenStock> $taken
     */
    public function record(array $taken, StockNote $note = new StockNote(StockReason::Sale)): void
    {
        $this->logParts($taken, -1, $note);
    }

    /**
     * Puts back stock taken for an order, as when the order is cancelled. Stock of a stockroom that was deleted
     * since, or whose level of the item was removed, is put back as a new level when the stockroom still exists.
     *
     * @param list<TakenStock> $taken
     */
    public function giveBack(array $taken, StockNote $note = new StockNote(StockReason::Release)): void
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
        $this->logParts($taken, 1, $note);
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
     * @param list<Stock> $stocks
     *
     * @return array<string, array<int, int>> levels by item, then stockroom id
     */
    private static function levelsBy(array $stocks): array
    {
        $levels = [];
        foreach ($stocks as $stock) {
            foreach ($stock->levels() as $level) {
                $levels[(string) $stock->item][$level->stockroom()->id()] = $level->quantity();
            }
        }

        return $levels;
    }

    /**
     * Keeps what the shop changed by hand: each stockroom's level that moved, from what it was.
     *
     * @param array<string, array<int, int>> $was
     * @param list<StockItem>                $items
     */
    private function log(array $was, array $items, StockNote $note): void
    {
        if ($this->movements === null) {
            return;
        }
        $now = self::levelsBy($this->levels->stockOf($items));
        $moves = [];
        foreach ($items as $item) {
            $key = (string) $item;
            foreach (array_keys(($was[$key] ?? []) + ($now[$key] ?? [])) as $room) {
                $delta = ($now[$key][$room] ?? 0) - ($was[$key][$room] ?? 0);
                if ($delta !== 0) {
                    $moves[] = [$item, $room, $delta, $now[$key][$room] ?? 0];
                }
            }
        }
        $this->keep($moves, $note);
    }

    /**
     * Keeps stock taken or given back for an order.
     *
     * @param list<TakenStock> $parts
     * @param int              $sign  -1 taken, 1 given back
     */
    private function logParts(array $parts, int $sign, StockNote $note): void
    {
        if ($this->movements === null || $parts === []) {
            return;
        }
        $items = [];
        foreach ($parts as $part) {
            $items[$part->item] = StockItem::fromString($part->item);
        }
        $now = self::levelsBy($this->levels->stockOf(array_values($items)));
        $moves = [];
        foreach ($parts as $part) {
            if ($part->quantity !== 0) {
                $moves[] = [$items[$part->item], $part->stockroomId, $sign * $part->quantity, $now[$part->item][$part->stockroomId] ?? 0];
            }
        }
        $this->keep($moves, $note);
    }

    /**
     * @param list<array{StockItem, int, int, int}> $moves item, stockroom, delta, level after
     */
    private function keep(array $moves, StockNote $note): void
    {
        if ($this->movements === null || $moves === []) {
            return;
        }
        $names = [];
        foreach ($this->stockrooms->all() as $stockroom) {
            $names[$stockroom->id()] = $stockroom->details()->name;
        }
        $at = $this->clock?->now() ?? new \DateTimeImmutable();
        $this->movements->add(array_map(
            static fn(array $move): StockMovement => new StockMovement($move[0], $move[1], $names[$move[1]] ?? "#{$move[1]}", $move[2], $move[3], $note->reason, $note->orderId, $note->userId, $at),
            $moves,
        ));
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
