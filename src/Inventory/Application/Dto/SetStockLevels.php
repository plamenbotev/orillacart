<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: replace an item's stock levels. Stockrooms not listed hold none of it afterwards;
 * an empty list means stock no longer limits the item.
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
