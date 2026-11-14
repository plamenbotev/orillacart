<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Infrastructure\Retention;

use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Settings\Application\KeptHistory;

/** Stock movements. */
final readonly class ForgetStockMovements implements KeptHistory
{
    public function __construct(private StockService $stock) {}

    public function forgetBefore(\DateTimeImmutable $before): void
    {
        $this->stock->forgetMovementsBefore($before);
    }
}
