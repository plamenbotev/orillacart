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
        #[Assert\Length(max: 20)]
        public ?string $billingPostcode = null,
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
        /** A business customer's VAT number, for the reverse charge, as checkout decides it. */
        #[Assert\Length(max: 20)]
        #[Assert\Regex(pattern: '/^[A-Za-z0-9 .\-]*$/', message: 'A VAT number has letters, digits, spaces, dots and dashes.')]
        public ?string $vatNumber = null,
        /**
         * Answers to the checkout's questions (add-ons' checkout fields and the shop's forms), by field id, as a
         * shopper would give them: a ticked box true, a choice by its value. Checked as at checkout; an add-on's
         * order adjuster prices by them (a "use my points" box).
         *
         * @var array<string, string|bool|int>
         */
        #[Assert\Count(max: 100)]
        #[Assert\All([new Assert\Type(['string', 'bool', 'int'])])]
        public array $fields = [],
    ) {}
}
