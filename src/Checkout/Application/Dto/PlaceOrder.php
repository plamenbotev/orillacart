<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: order what is in the cart. Without a shipping address, the order ships to the billing address.
 * A shipping rate is needed when something ships, a payment method when there is something to pay.
 * A visitor may make an account with the order (create_account and a password); a logged-in customer's
 * addresses are remembered for next time unless remember_addresses is false. Fields holds the answers to the
 * questions add-ons ask.
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
        #[Assert\Regex(pattern: ShippingOptionView::ID_PATTERN)]
        public ?string $shippingOption = null,
        #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{0,39}$/')]
        public ?string $payment = null,
        #[Assert\Length(max: Order::MAX_NOTE_LENGTH)]
        public string $note = '',
        public bool $createAccount = false,
        #[Assert\Length(max: 200)]
        public ?string $password = null,
        public bool $rememberAddresses = true,
        /** The office or locker to deliver to, for a service to one (the courier's id). */
        #[Assert\Length(max: 64)]
        public ?string $pickupPoint = null,
        /** @var array<string, string|bool> answers to add-ons' checkout fields, by field id */
        #[Assert\Count(max: 100)]
        #[Assert\All([new Assert\Type(['string', 'bool', 'int'])])]
        public array $fields = [],
        /** A business customer's VAT number, for the reverse charge. */
        #[Assert\Length(max: 20)]
        public ?string $vatNumber = null,
    ) {}
}
