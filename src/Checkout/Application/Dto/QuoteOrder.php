<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query: what an order the shop is taking would cost (countries are ISO 3166-1 codes), and how it could ship and be paid, before it is created.
 */
final readonly class QuoteOrder
{
    /**
     * @param list<EditedItem> $items
     */
    public function __construct(
        #[Assert\Valid]
        public array $items = [],
        #[Assert\Regex(pattern: '/^[A-Z]{2}$/')]
        public ?string $billingCountry = null,
        #[Assert\Regex(pattern: '/^[A-Z0-9]{1,3}$/')]
        public ?string $billingState = null,
        #[Assert\Regex(pattern: '/^[A-Z]{2}$/')]
        public ?string $shippingCountry = null,
        #[Assert\Regex(pattern: '/^[A-Z0-9]{1,3}$/')]
        public ?string $shippingState = null,
        #[Assert\Length(max: 20)]
        public ?string $shippingPostcode = null,
        #[Assert\Regex(pattern: ShippingOptionView::ID_PATTERN)]
        public ?string $shippingOption = null,
        /** Whose order: automatic discounts for customer groups or first orders depend on it. */
        #[Assert\Positive]
        public ?int $customerId = null,
        #[Assert\Length(max: 100)]
        public ?string $email = null,
        /** The way it is paid, for its fee. */
        #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{0,39}$/')]
        public ?string $payment = null,
    ) {}
}
