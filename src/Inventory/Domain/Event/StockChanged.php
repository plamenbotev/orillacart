<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * Stock levels of an item were set, taken or given back. Announced as the action orillacart_stock_changed with
 * the item, such as "product:12", and how many could be sold before and after (null: stock did not limit it).
 */
final readonly class StockChanged implements PublicEvent
{
    public function __construct(
        public string $item,
        public ?int $before = null,
        public ?int $after = null,
    ) {}

    /**
     * Whether the item ran out or came back: what a shopper filtering by stock sees change. Selling 8 of 10
     * changes nothing there.
     */
    public function changedAvailability(): bool
    {
        return self::sellable($this->before) !== self::sellable($this->after);
    }

    public function hookName(): string
    {
        return 'stock_changed';
    }

    /**
     * @return array{string, ?int, ?int}
     */
    public function hookArguments(): array
    {
        return [$this->item, $this->before, $this->after];
    }

    private static function sellable(?int $available): bool
    {
        return $available === null || $available > 0;
    }
}
