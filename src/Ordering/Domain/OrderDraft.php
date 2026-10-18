<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * Everything checkout settled for an order: who, what, how it ships and is paid, and the totals.
 */
final readonly class OrderDraft
{
    /**
     * @param list<LineDraft> $lines
     * @param list<TaxLine>   $taxes
     * @param Money           $subtotal      the items before tax and before the discounts
     * @param list<OrderDiscount> $discounts
     * @param list<FieldAnswer>   $fields    answers to add-ons' checkout fields
     */
    public function __construct(
        public string $key,
        public ?int $customerId,
        public string $email,
        public Address $billing,
        public ?Address $shippingAddress,
        public array $lines,
        public ?ShippingChoice $shipping,
        public array $taxes,
        public Money $subtotal,
        public Money $itemsTax,
        public Money $total,
        public string $currency,
        public ?string $paymentGateway,
        public string $paymentName,
        public string $paymentNote,
        public string $note,
        public \DateTimeImmutable $placedAt,
        public ?Money $discountTotal = null,
        public array $discounts = [],
        public array $fields = [],
        public bool $pricesIncludeTax = false,
    ) {}
}
