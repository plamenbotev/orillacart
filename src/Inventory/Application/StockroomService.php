<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application;

use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\StockroomView;
use OrillaCart\Inventory\Domain\DeliveryUnit;
use OrillaCart\Inventory\Domain\Event\StockChanged;
use OrillaCart\Inventory\Domain\InventoryRuleViolation;
use OrillaCart\Inventory\Domain\Stock;
use OrillaCart\Inventory\Domain\StockItem;
use OrillaCart\Inventory\Domain\StockLevel;
use OrillaCart\Inventory\Domain\StockLevelRepository;
use OrillaCart\Inventory\Domain\Stockroom;
use OrillaCart\Inventory\Domain\StockroomDetails;
use OrillaCart\Inventory\Domain\StockroomNotFound;
use OrillaCart\Inventory\Domain\StockroomRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Use cases for stockrooms, as the Stockrooms admin screen runs them.
 */
final readonly class StockroomService
{
    public function __construct(
        private StockroomRepository $stockrooms,
        private StockLevelRepository $levels,
        private EventDispatcherInterface $events,
    ) {}

    /**
     * @return list<StockroomView> by name
     */
    public function stockrooms(): array
    {
        return array_map(StockroomView::fromStockroom(...), $this->stockrooms->all());
    }

    public function create(SaveStockroom $command): StockroomView
    {
        $stockroom = new Stockroom(self::details($command));
        $this->stockrooms->save($stockroom);

        return StockroomView::fromStockroom($stockroom);
    }

    /**
     * @throws StockroomNotFound
     */
    public function update(int $id, SaveStockroom $command): StockroomView
    {
        $stockroom = $this->get($id);
        $wasActive = $stockroom->isActive();
        $held = $wasActive === $command->active ? [] : $this->levels->stockOf($this->itemsIn($id));
        $before = self::availableExcept($held, null);
        $stockroom->change(self::details($command));
        $this->stockrooms->save($stockroom);
        // Turned on or off: what it holds counts or stops counting.
        $this->announce($before, self::availableExcept($held, null));

        return StockroomView::fromStockroom($stockroom);
    }

    /**
     * Deletes the stockroom and the stock it holds. Not while it is the only one holding some items: they would sell
     * without limit (an item no stockroom holds is not limited), so the shop turns it off instead, and they count
     * as out of stock.
     *
     * @throws StockroomNotFound
     * @throws InventoryRuleViolation
     */
    public function delete(int $id): void
    {
        $stockroom = $this->get($id);
        $only = $this->levels->itemsOnlyIn($id);
        if ($only > 0) {
            throw new InventoryRuleViolation('%1$s is the only stockroom holding %2$d item(s), which would then sell without limit. Turn it off instead, or count them in another stockroom first.', 'orillacart_stockroom_in_use', values: [$stockroom->details()->name, $only]);
        }
        $held = $stockroom->isActive() ? $this->levels->stockOf($this->itemsIn($id)) : [];
        $before = self::availableExcept($held, null);
        $after = self::availableExcept($held, $id);
        $this->stockrooms->remove($stockroom);
        $this->announce($before, $after);
    }

    /** @return list<StockItem> */
    private function itemsIn(int $id): array
    {
        return array_map(static fn(int|string $item): StockItem => StockItem::fromString((string) $item), array_keys($this->levels->totals($id)));
    }

    /**
     * How many of each can be sold, as Stock::available() counts: the levels of active stockrooms.
     *
     * @param list<Stock> $stocks
     * @param int|null    $gone   a stockroom being deleted, counted as gone
     *
     * @return array<string, int>
     */
    private static function availableExcept(array $stocks, ?int $gone): array
    {
        $available = [];
        foreach ($stocks as $stock) {
            $available[(string) $stock->item] = array_sum(array_map(
                static fn(StockLevel $level): int => $level->stockroom()->isActive() && $level->stockroom()->id() !== $gone ? $level->quantity() : 0,
                $stock->levels(),
            ));
        }

        return $available;
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     */
    private function announce(array $before, array $after): void
    {
        foreach ($after as $item => $available) {
            if ($available !== ($before[$item] ?? null)) {
                $this->events->dispatch(new StockChanged($item, $before[$item] ?? null, $available));
            }
        }
    }

    private function get(int $id): Stockroom
    {
        return $this->stockrooms->find($id) ?? throw new StockroomNotFound($id);
    }

    private static function details(SaveStockroom $command): StockroomDetails
    {
        return new StockroomDetails($command->name, $command->minDeliveryDays, $command->maxDeliveryDays, $command->active, $command->description, DeliveryUnit::from($command->deliveryUnit), $command->lowStockThreshold);
    }
}
