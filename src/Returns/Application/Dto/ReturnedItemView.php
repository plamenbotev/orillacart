<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

/**
 * How many of one of the order's lines go back, or may.
 */
final readonly class ReturnedItemView
{
    public function __construct(
        public int $line,
        public string $name,
        public int $quantity,
    ) {}
}
