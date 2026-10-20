<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use OrillaCart\Inventory\Domain\StockReason;

/**
 * Why stock moves, for the stock history: the reason, and the order or the user it was for.
 */
final readonly class StockNote
{
    public function __construct(
        public StockReason $reason = StockReason::Count,
        public ?int $orderId = null,
        public ?int $userId = null,
    ) {}
}
