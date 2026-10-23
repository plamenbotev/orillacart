<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

interface StockLevelRepository
{
    /**
     * @param list<StockItem> $items
     *
     * @return list<Stock> one per item, in the same order
     */
    public function stockOf(array $items): array;

    /**
     * Runs the work in one transaction, with the items' stock levels locked until it ends, so two orders cannot
     * take the same stock; changes to the levels are saved when the work returns.
     *
     * @template T
     *
     * @param list<StockItem>              $items
     * @param \Closure(list<Stock>): T $work
     *
     * @return T
     */
    public function lockedStockOf(array $items, \Closure $work): mixed;

    /**
     * The levels of active stockrooms holding this many or fewer, with their stockrooms.
     *
     * @return list<StockLevel>
     */
    public function atMost(int $quantity): array;

    /**
     * How many of each item active stockrooms, or the one given (active or not), hold in all; items without a
     * level are left out.
     *
     * @return array<string, int> by item ("variation:12")
     */
    public function totals(?int $stockroomId = null): array;

    /**
     * The items an active stockroom, or the one given, owes: its level is below zero.
     *
     * @return list<StockItem>
     */
    public function itemsOwed(?int $stockroomId = null): array;

    public function add(StockLevel $level): void;

    public function remove(StockLevel $level): void;

    /** Saves changes to levels loaded by stockOf(). */
    public function flush(): void;

    /**
     * @param list<StockItem> $items
     */
    public function removeItems(array $items): void;
}
