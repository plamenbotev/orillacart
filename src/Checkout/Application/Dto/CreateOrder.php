<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the shop takes an order itself, as on the phone or in person. It is priced and takes stock as checkout
 * does; items may carry the shop's own unit prices. A paid order starts processing; an unpaid one waits on hold
 * for the payment method chosen. Without a shipping address, it ships to the billing address. The customer is
 * emailed about it only when the shop asks; the shop, which took it, never is.
 */
final readonly class CreateOrder
{
    /**
     * @param list<EditedItem> $items
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 200)]
        public string $email,
        #[Assert\Valid]
        public AddressData $billing,
        #[Assert\Count(min: 1, minMessage: 'An order has at least one item.')]
        #[Assert\Valid]
        public array $items,
        #[Assert\Valid]
        public ?AddressData $shippingAddress = null,
        #[Assert\Positive]
        public ?int $customerId = null,
        #[Assert\Regex(pattern: ShippingOptionView::ID_PATTERN)]
        public ?string $shippingOption = null,
        #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{0,39}$/')]
        public ?string $payment = null,
        public bool $paid = false,
        #[Assert\Length(max: Order::MAX_NOTE_LENGTH)]
        public string $note = '',
        public bool $emailCustomer = false,
    ) {}
}
