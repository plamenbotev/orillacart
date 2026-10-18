<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use OrillaCart\Inventory\Domain\InventoryRuleViolation;
use OrillaCart\Inventory\Domain\StockItem;
use OrillaCart\Inventory\Domain\StockItemKind;

/**
 * Names something the shop counts stock of: a product, a variation or an attribute option, by its catalog id.
 */
final readonly class StockItemRef
{
    public function __construct(
        public string $kind,
        public int $id,
    ) {}

    public static function product(int $id): self
    {
        return new self(StockItemKind::Product->value, $id);
    }

    public static function variation(int $id): self
    {
        return new self(StockItemKind::Variation->value, $id);
    }

    public static function option(int $id): self
    {
        return new self(StockItemKind::Option->value, $id);
    }

    /**
     * Reads the item as StockChanged names it: "product:12".
     *
     * @throws InventoryRuleViolation for anything else
     */
    public static function fromString(string $item): self
    {
        $parsed = StockItem::fromString($item);

        return new self($parsed->kind->value, $parsed->id);
    }

    /**
     * @internal for the Inventory application services
     */
    public function toItem(): StockItem
    {
        return new StockItem(StockItemKind::from($this->kind), $this->id);
    }
}
