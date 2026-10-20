<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

/**
 * How many of an item one stockroom holds; below zero by what it owes.
 */
final readonly class ItemLevelView
{
    public function __construct(
        public StockItemRef $item,
        public int $stockroomId,
        public int $quantity,
    ) {}
}
