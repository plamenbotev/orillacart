<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use OrillaCart\Inventory\Domain\Stock;
use OrillaCart\Inventory\Domain\StockLevel;

final readonly class StockView
{
    /**
     * @param string               $item      such as "product:12"
     * @param list<StockLevelView> $levels    by stockroom id
     * @param int|null             $available how many can be sold; null when stock does not limit the item
     */
    public function __construct(
        public string $item,
        public array $levels,
        public ?int $available,
    ) {}

    public static function fromStock(Stock $stock): self
    {
        $views = array_map(static fn(StockLevel $level): StockLevelView => new StockLevelView($level->stockroom()->id(), $level->quantity()), $stock->levels());
        usort($views, static fn(StockLevelView $a, StockLevelView $b): int => $a->stockroomId <=> $b->stockroomId);

        return new self((string) $stock->item, $views, $stock->available());
    }
}
