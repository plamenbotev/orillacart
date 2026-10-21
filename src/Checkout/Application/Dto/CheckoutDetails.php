<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query: the checkout totals for what the customer has entered so far. Countries are ISO 3166-1 codes;
 * without a billing country, the shipping one is used.
 */
final readonly class CheckoutDetails
{
    public function __construct(
        #[Assert\Regex(pattern: '/^[A-Z]{2}$/')]
        public ?string $shippingCountry = null,
        #[Assert\Regex(pattern: '/^[A-Z0-9]{1,3}$/')]
        public ?string $shippingState = null,
        #[Assert\Regex(pattern: '/^[A-Z]{2}$/')]
        public ?string $billingCountry = null,
        #[Assert\Regex(pattern: '/^[A-Z0-9]{1,3}$/')]
        public ?string $billingState = null,
        #[Assert\Regex(pattern: ShippingOptionView::ID_PATTERN)]
        public ?string $shippingOption = null,
        #[Assert\Length(max: 20)]
        public ?string $shippingPostcode = null,
        /** The office or locker to deliver to, for a service to one (the courier's id). */
        #[Assert\Length(max: 64)]
        public ?string $pickupPoint = null,
        /** A business customer's VAT number, for the reverse charge. */
        #[Assert\Length(max: 20)]
        public ?string $vatNumber = null,
        /** The way to pay chosen, for its fee; without it, the first offered. */
        #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{0,39}$/')]
        public ?string $payment = null,
        /** The day chosen for delivery, "2026-10-21", and the time of day, when the way to ship offers them. */
        #[Assert\Date]
        public ?string $deliveryDate = null,
        #[Assert\Length(max: 40)]
        public ?string $deliverySlot = null,
        /** For taxes charged at some postcodes only, when the billing address decides. */
        #[Assert\Length(max: 20)]
        public ?string $billingPostcode = null,
    ) {}
}
