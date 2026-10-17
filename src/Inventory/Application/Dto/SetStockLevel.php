<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SetStockLevel
{
    public function __construct(
        #[Assert\Positive]
        public int $stockroomId,
        #[Assert\PositiveOrZero]
        public int $quantity,
    ) {}
}
