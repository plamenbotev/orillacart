<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Domain\Order;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: order what is in the cart. Without a shipping address, the order ships to the billing address.
 * A shipping rate is needed when something ships, a payment method when there is something to pay.
 */
final readonly class PlaceOrder
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 200)]
        public string $email,
        #[Assert\Valid]
        public AddressData $billing,
        #[Assert\Valid]
        public ?AddressData $shippingAddress = null,
        #[Assert\Positive]
        public ?int $rateId = null,
        #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{0,39}$/')]
        public ?string $payment = null,
        #[Assert\Length(max: Order::MAX_NOTE_LENGTH)]
        public string $note = '',
    ) {}
}
