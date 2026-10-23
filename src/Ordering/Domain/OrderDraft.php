<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * Everything checkout settled for an order: who, what, how it ships and is paid, and the total, which is what its
 * lines (items, shipping and fees) come to with their adjustments and taxes.
 */
final readonly class OrderDraft
{
    /**
     * @param list<LineDraft>   $lines  the items, then the shipping and the fees
     * @param list<FieldAnswer> $fields answers to add-ons' checkout fields
     * @param array{reason: string, certificate: string}|null $taxExemption the customer's exemption that covered it
     */
    public function __construct(
        public ?int $customerId,
        public string $email,
        public Address $billing,
        public ?Address $shippingAddress,
        public array $lines,
        public ?ShippingChoice $shipping,
        public Money $total,
        public string $currency,
        public ?string $paymentGateway,
        public string $paymentName,
        public string $paymentNote,
        public string $note,
        public \DateTimeImmutable $placedAt,
        public array $fields = [],
        public bool $pricesIncludeTax = false,
        public ?string $vatNumber = null,
        public bool $reverseCharge = false,
        public ?string $locale = null,
        public ?DeliveryChoice $delivery = null,
        public ?array $taxExemption = null,
        /** The tax service that worked out its taxes, by its id; null for the shop's own rates. */
        public ?string $taxProvider = null,
        public ?string $importScheme = null,
        public ?string $importNumber = null,
    ) {}
}
