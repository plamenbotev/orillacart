<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
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
        #[Assert\Regex(pattern: ShippingOptionView::ID_PATTERN)]
        public ?string $shippingOption = null,
        /** The office or locker to deliver to, for a service to one (the courier's id). */
        #[Assert\Length(max: 64)]
        public ?string $pickupPoint = null,
    ) {}
}
