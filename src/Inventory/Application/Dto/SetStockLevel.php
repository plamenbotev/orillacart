<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * How many of an item one stockroom holds: a quantity, with the quantity loaded as `was` (the save is refused with
 * 409 orillacart_stock_changed when the level moved since, as a sale moves it); or a change, added to the level as
 * it is then (below zero to take).
 *
 * @param int|null $quantity the level to set
 * @param int|null $was      the level loaded, null when the stockroom had none: required with a quantity
 * @param int|null $change   how many to add, below zero to take, in place of a quantity and was
 */
final readonly class SetStockLevel
{
    public function __construct(
        #[Assert\Positive]
        public int $stockroomId,
        #[Assert\PositiveOrZero]
        public ?int $quantity = null,
        public ?int $was = null,
        public ?int $change = null,
    ) {}
}
