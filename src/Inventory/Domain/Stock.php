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
     * fast ones, from the one with the least of the item, so small remainders are used up.
     *
     * @return array<int, int> stockroom id => quantity taken; empty when stock does not limit the item
     *
     * @throws InsufficientStock and takes nothing when the active stockrooms hold too few
     */
    public function take(int $quantity): array
    {
        if ($quantity < 1) {
            throw new InventoryRuleViolation('Take at least one.', 'orillacart_invalid_quantity');
        }
        $available = $this->available();
        if ($available === null) {
            return [];
        }
        if ($available < $quantity) {
            throw new InsufficientStock($this->item, $quantity, $available);
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

        return $taken;
    }
}
