<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application;

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
        $this->events->dispatch(new StockChanged((string) $item));

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
     * Takes the quantity of each item for a placed order: all of it or, when one item falls short, nothing.
     *
     * @param list<StockItemRef> $refs
     *
     * @return list<TakenStock>
     *
     * @throws InsufficientStock
     */
    public function take(array $refs, int $quantity): array
    {
        $items = array_map(static fn(StockItemRef $ref): StockItem => $ref->toItem(), $refs);

        $taken = $this->levels->lockedStockOf($items, static function (array $stocks) use ($quantity): array {
            /** @var list<Stock> $stocks */
            foreach ($stocks as $stock) {
                $available = $stock->available();
                if ($available !== null && $available < $quantity) {
                    throw new InsufficientStock($stock->item, $quantity, $available);
                }
            }
            $taken = [];
            foreach ($stocks as $stock) {
                foreach ($stock->take($quantity) as $stockroomId => $amount) {
                    $taken[] = new TakenStock((string) $stock->item, $stockroomId, $amount);
                }
            }

            return $taken;
        });
        foreach ($items as $item) {
            $this->events->dispatch(new StockChanged((string) $item));
        }

        return $taken;
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
