<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use OrillaCart\Inventory\Domain\StockItemKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: one cell of the stock sheet, how many of an item one stockroom holds. Null removes the level: the
 * stockroom then holds none of it, and an item with no levels at all is not limited by stock.
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
        public ?int $quantity,
    ) {}

    public function ref(): StockItemRef
    {
        return new StockItemRef($this->kind, $this->id);
    }
}
