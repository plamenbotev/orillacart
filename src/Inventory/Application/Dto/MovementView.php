<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use OrillaCart\Inventory\Domain\StockMovement;

/**
 * One move of a stock level, as the stock history shows it.
 */
final readonly class MovementView
{
    public function __construct(
        public int $id,
        public StockItemRef $item,
        public int $stockroomId,
        public string $stockroom,
        public int $delta,
        public int $after,
        public string $reason,
        public ?int $orderId,
        public ?int $userId,
        public string $at,
    ) {}

    public static function fromMovement(StockMovement $movement): self
    {
        $item = $movement->item();

        return new self($movement->id(), new StockItemRef($item->kind->value, $item->id), $movement->stockroomId(), $movement->stockroom(), $movement->delta(), $movement->after(), $movement->reason()->value, $movement->orderId(), $movement->userId(), $movement->at()->format(\DATE_ATOM));
    }
}
