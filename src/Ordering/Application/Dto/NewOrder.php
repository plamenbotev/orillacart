<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * Command from checkout: record a placed order. Amounts are decimal strings rounded to the currency.
 */
final readonly class NewOrder
{
    /**
     * @param list<NewOrderLine> $lines
     * @param list<TaxLineView>  $taxes
     * @param string             $subtotal      the items before tax and before the discounts
     * @param string             $discountTotal what the coupons took off the items
     * @param list<OrderDiscountView> $discounts
     * @param list<OrderFieldView>    $fields    answers to add-ons' checkout fields
     * @param list<NewOrderFee>       $fees      what it pays besides its items and shipping
     */
    public function __construct(
        public ?int $customerId,
        public string $email,
        public AddressData $billing,
        public ?AddressData $shippingAddress,
        public array $lines,
        public ?NewShipping $shipping,
        public array $taxes,
        public string $subtotal,
        public string $itemsTax,
        public string $total,
        public string $currency,
        public ?string $paymentGateway,
        public string $paymentName,
        public string $paymentNote,
        public string $note,
        public string $discountTotal = '0',
        public array $discounts = [],
        public array $fields = [],
        /** The amounts include tax: the total is not the subtotal plus tax. */
        public bool $pricesIncludeTax = false,
        /** The business customer's VAT number, as checked. */
        public ?string $vatNumber = null,
        /** Sold without VAT: the customer accounts for it (reverse charge). */
        public bool $reverseCharge = false,
        /** The language the customer ordered in; null for the site's. */
        public ?string $locale = null,
        public array $fees = [],
        /** The tax on the fees. */
        public string $feesTax = '0',
        /** The day the customer chose for delivery, "2026-10-21", with the time of day and what it counts against. */
        public ?string $deliveryDate = null,
        public ?string $deliverySlot = null,
        public ?string $deliveryKey = null,
    ) {}
}
