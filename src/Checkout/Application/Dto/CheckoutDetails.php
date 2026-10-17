<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

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
        #[Assert\Positive]
        public ?int $rateId = null,
    ) {}
}
