<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

/**
 * The stock levels of one item across the stockrooms, and the rules for selling from them.
 *
 * Only active stockrooms count. An item that has no stock level in any stockroom, active or not, is not limited
 * by stock. Carts do not hold stock: it is taken when an order is placed.
 */
final readonly class Stock
{
    /**
     * @param list<StockLevel> $levels every level of the item
     */
    public function __construct(
        public StockItem $item,
        private array $levels,
    ) {
        foreach ($levels as $level) {
            if (!$level->item()->equals($item)) {
                throw new \LogicException(\sprintf('A stock level of %s is not one of %s.', $level->item(), $item));
            }
        }
    }

    /**
     * @return list<StockLevel>
     */
    public function levels(): array
    {
        return $this->levels;
    }

    public function isLimited(): bool
    {
        return $this->levels !== [];
    }

    /**
     * @return int|null how many can be sold; null when stock does not limit the item
     */
    public function available(): ?int
    {
        if (!$this->isLimited()) {
            return null;
        }

        return array_sum(array_map(
            static fn(StockLevel $level): int => $level->stockroom()->isActive() ? $level->quantity() : 0,
            $this->levels,
        ));
    }

    /**
     * Takes stock for a placed order: from the active stockroom that delivers fastest first and, between equally
     * fast ones, from the one with the least of the item, so small remainders are used up. With backorders, what
     * the stockrooms do not hold is owed by the fastest active stockroom, whose level goes below zero.
     *
     * @return array<int, int> stockroom id => quantity taken, owed part included; empty when stock does not limit
     *                         the item
     *
     * @throws InsufficientStock and takes nothing when the active stockrooms hold too few and the item takes no
     *                           backorders, or no active stockroom has a level of it to owe from
     */
    public function take(int $quantity, bool $backorders = false): array
    {
        if ($quantity < 1) {
            throw new InventoryRuleViolation('Take at least one.', 'orillacart_invalid_quantity');
        }
        $available = $this->available();
        if ($available === null) {
            return [];
        }
        $owing = $this->owingLevel();
        if ($available < $quantity && (!$backorders || $owing === null)) {
            throw new InsufficientStock($this->item, $quantity, max(0, $available));
        }

        $levels = array_values(array_filter($this->levels, static fn(StockLevel $level): bool => $level->stockroom()->isActive() && $level->quantity() > 0));
        usort($levels, static fn(StockLevel $a, StockLevel $b): int => [$a->stockroom()->maxDeliveryDays(), $a->quantity(), $a->stockroom()->id()]
            <=> [$b->stockroom()->maxDeliveryDays(), $b->quantity(), $b->stockroom()->id()]);

        $taken = [];
        foreach ($levels as $level) {
            $amount = min($quantity, $level->quantity());
            $level->take($amount);
            $taken[$level->stockroom()->id()] = $amount;
            $quantity -= $amount;
            if ($quantity === 0) {
                break;
            }
        }
        if ($quantity > 0 && $owing !== null) {
            $owing->owe($quantity);
            $id = $owing->stockroom()->id();
            $taken[$id] = ($taken[$id] ?? 0) + $quantity;
        }

        return $taken;
    }

    /** Whether a backorder can be taken: an active stockroom has a level of the item to owe it from. */
    public function canOwe(): bool
    {
        return $this->owingLevel() !== null;
    }

    /** The stockroom that owes what is backordered: the active one that delivers fastest. */
    public function owingStockroomId(): ?int
    {
        return $this->owingLevel()?->stockroom()->id();
    }

    private function owingLevel(): ?StockLevel
    {
        $active = array_values(array_filter($this->levels, static fn(StockLevel $level): bool => $level->stockroom()->isActive()));
        usort($active, static fn(StockLevel $a, StockLevel $b): int => [$a->stockroom()->maxDeliveryDays(), $a->stockroom()->id()] <=> [$b->stockroom()->maxDeliveryDays(), $b->stockroom()->id()]);

        return $active[0] ?? null;
    }

    /**
     * Puts back stock an order took from a stockroom, as when the order is cancelled.
     *
     * @return bool false when the item has no level in that stockroom any more
     */
    public function putBack(int $stockroomId, int $quantity): bool
    {
        foreach ($this->levels as $level) {
            if ($level->stockroom()->id() === $stockroomId) {
                $level->putBack($quantity);

                return true;
            }
        }

        return false;
    }
}
