<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: an item's stock levels, at most one per stockroom. Saved through the stock route, the stockrooms listed
 * change and the others keep their levels; an import (StockService::setLevels()) replaces them all.
 */
final readonly class SetStockLevels
{
    /**
     * @param list<SetStockLevel> $levels at most one per stockroom
     */
    public function __construct(
        #[Assert\Valid]
        public array $levels,
    ) {}
}
