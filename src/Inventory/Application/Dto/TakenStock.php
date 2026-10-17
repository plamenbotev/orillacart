<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

/**
 * Stock taken for an order from one stockroom, so it can be put back if the order is cancelled.
 */
final readonly class TakenStock
{
    public function __construct(
        public string $item,
        public int $stockroomId,
        public int $quantity,
    ) {}
}
