<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use OrillaCart\Inventory\Domain\StockItemKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: one cell of the stock sheet, how many of an item one stockroom holds. Either a quantity, with the
 * quantity loaded as `was`: the level is set when it is still what was loaded, and the whole save is refused (409
 * orillacart_stock_changed) when it moved since, as a sale moves it. Or a change, added to the level as it is then
 * (below zero to take). A quantity of null removes the level: the stockroom then holds none of it, and an item with
 * no levels at all is not limited by stock.
 *
 * @param int|null $quantity the level to set; null with no change removes it
 * @param int|null $was      the level loaded, null when the stockroom had none: required with a quantity
 * @param int|null $change   how many to add, below zero to take, in place of a quantity and was
 */
final readonly class ChangeStockLevel
{
    public function __construct(
        #[Assert\Choice(callback: [StockItemKind::class, 'values'])]
        public string $kind,
        #[Assert\Positive]
        public int $id,
        #[Assert\Positive]
        public int $stockroomId,
        #[Assert\PositiveOrZero]
        public ?int $quantity = null,
        public ?int $was = null,
        public ?int $change = null,
    ) {}

    public function ref(): StockItemRef
    {
        return new StockItemRef($this->kind, $this->id);
    }
}
