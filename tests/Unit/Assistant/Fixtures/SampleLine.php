<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant\Fixtures;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SampleLine
{
    /**
     * @param int $line the line's position, from 0
     */
    public function __construct(
        #[Assert\PositiveOrZero]
        public int $line,
        #[Assert\Positive]
        public int $quantity = 1,
    ) {}
}
