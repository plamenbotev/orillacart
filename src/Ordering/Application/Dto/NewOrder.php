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
    ) {}
}
