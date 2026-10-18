<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Items of an order line a refund covers.
 */
final readonly class RefundLine
{
    /**
     * @param int $line the line's position, from 0
     */
    public function __construct(
        #[Assert\PositiveOrZero]
        public int $line,
        #[Assert\Positive]
        public int $quantity,
    ) {}
}
