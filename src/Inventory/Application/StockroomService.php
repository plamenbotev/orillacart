<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application;

use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\StockroomView;
use OrillaCart\Inventory\Domain\DeliveryUnit;
use OrillaCart\Inventory\Domain\InventoryRuleViolation;
use OrillaCart\Inventory\Domain\StockLevelRepository;
use OrillaCart\Inventory\Domain\Stockroom;
use OrillaCart\Inventory\Domain\StockroomDetails;
use OrillaCart\Inventory\Domain\StockroomNotFound;
use OrillaCart\Inventory\Domain\StockroomRepository;

/**
 * Use cases for stockrooms, as the Stockrooms admin screen runs them.
 */
final readonly class StockroomService
{
    public function __construct(
        private StockroomRepository $stockrooms,
        private StockLevelRepository $levels,
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
        $stockroom->change(self::details($command));
        $this->stockrooms->save($stockroom);

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
            throw new InventoryRuleViolation(\sprintf(
                '%s is the only stockroom holding %d item(s), which would then sell without limit. Turn it off instead, or count them in another stockroom first.',
                $stockroom->details()->name,
                $only,
            ), 'orillacart_stockroom_in_use');
        }
        $this->stockrooms->remove($stockroom);
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
