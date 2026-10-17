<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

/**
 * Stock an order line took from a stockroom, as Inventory reported it.
 */
final readonly class TakenItem
{
    public function __construct(
        public string $item,
        public int $stockroomId,
        public int $quantity,
    ) {}
}
