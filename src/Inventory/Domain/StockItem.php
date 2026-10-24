<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

/**
 * Something the shop counts stock of, named by its catalog id.
 */
final readonly class StockItem implements \Stringable
{
    public function __construct(
        public StockItemKind $kind,
        public int $id,
    ) {
        if ($id < 1) {
            throw new InventoryRuleViolation('A stock item id is a positive number.', 'orillacart_invalid_stock_item');
        }
    }

    /** Parses "product:12", as __toString() writes it. */
    public static function fromString(string $item): self
    {
        [$kind, $id] = array_pad(explode(':', $item, 2), 2, '');
        $found = StockItemKind::tryFrom($kind);
        if ($found === null || preg_match('/^\d+$/', $id) !== 1) {
            throw new InventoryRuleViolation('"%s" is not a stock item.', 'orillacart_invalid_stock_item', values: [$item]);
        }

        return new self($found, (int) $id);
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->id === $other->id;
    }

    /** "product:12" */
    public function __toString(): string
    {
        return $this->kind->value . ':' . $this->id;
    }
}
