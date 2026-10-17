<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * Stock levels of an item were set or taken. Announced as the action orillacart_stock_changed with the item,
 * such as "product:12".
 */
final readonly class StockChanged implements PublicEvent
{
    public function __construct(public string $item) {}

    public function hookName(): string
    {
        return 'stock_changed';
    }

    /**
     * @return list<string>
     */
    public function hookArguments(): array
    {
        return [$this->item];
    }
}
