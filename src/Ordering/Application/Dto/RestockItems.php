<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: items of a refunded order came back, and go back in stock.
 */
final readonly class RestockItems
{
    /**
     * @param list<RefundLine> $lines
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'Name the items that came back.')]
        #[Assert\Valid]
        public array $lines,
    ) {}
}
