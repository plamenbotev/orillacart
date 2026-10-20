<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * How many of one of the order's lines go back. Lines count from 0, as the order lists them.
 */
final readonly class ReturnItem
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public int $line,
        #[Assert\Positive]
        public int $quantity,
    ) {}
}
