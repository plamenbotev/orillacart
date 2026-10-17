<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the cells of the stock sheet that changed, saved together.
 */
final readonly class ChangeStockLevels
{
    /**
     * @param list<ChangeStockLevel> $changes
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 2000)]
        #[Assert\Valid]
        public array $changes,
    ) {}
}
