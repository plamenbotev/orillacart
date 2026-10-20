<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

/**
 * Which items the last low stock email named, so the next names what ran low since.
 */
interface AlertedStock
{
    /** @return list<string> items in their stockrooms, as LowStockView::key() names them */
    public function keys(): array;

    /** @param list<string> $keys */
    public function remember(array $keys): void;
}
