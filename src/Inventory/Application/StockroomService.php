<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application;

use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\StockroomView;
use OrillaCart\Inventory\Domain\Stockroom;
use OrillaCart\Inventory\Domain\StockroomDetails;
use OrillaCart\Inventory\Domain\StockroomNotFound;
use OrillaCart\Inventory\Domain\StockroomRepository;

/**
 * Use cases for stockrooms, as the Stockrooms admin screen runs them.
 */
final readonly class StockroomService
{
    public function __construct(private StockroomRepository $stockrooms) {}

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
     * Deletes the stockroom and the stock it holds.
     *
     * @throws StockroomNotFound
     */
    public function delete(int $id): void
    {
        $this->stockrooms->remove($this->get($id));
    }

    private function get(int $id): Stockroom
    {
        return $this->stockrooms->find($id) ?? throw new StockroomNotFound($id);
    }

    private static function details(SaveStockroom $command): StockroomDetails
    {
        return new StockroomDetails($command->name, $command->minDeliveryDays, $command->maxDeliveryDays, $command->active, $command->description);
    }
}
