<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: change what an order on hold contains, and how it ships when something does.
 */
final readonly class EditOrderItems
{
    /**
     * @param list<EditedItem> $items
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'An order has at least one item.')]
        #[Assert\Valid]
        public array $items,
        #[Assert\Positive]
        public ?int $rateId = null,
    ) {}
}
