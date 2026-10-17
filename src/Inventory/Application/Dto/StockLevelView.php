<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

final readonly class StockLevelView
{
    public function __construct(
        public int $stockroomId,
        public int $quantity,
    ) {}
}
