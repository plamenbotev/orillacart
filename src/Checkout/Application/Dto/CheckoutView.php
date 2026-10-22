<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Forms\Application\Dto\AskedField;
use OrillaCart\Payment\Application\Dto\PaymentOptionView;
use OrillaCart\Shipping\Application\Dto\DeliveryChoiceView;
use OrillaCart\Shipping\Application\Dto\PickupPointView;

/**
 * Checkout as the customer sees it: the lines, the ways to ship and pay, and totals for the chosen shipping.
 */
final readonly class CheckoutView
{
    /**
     * @param list<CheckoutLineView>  $lines
     * @param list<ShippingChoiceView> $shippingOptions
     * @param list<PaymentOptionView> $paymentMethods
     * @param list<CheckoutTaxView>   $taxes
     * @param list<PickupPointView>   $pickupPoints where the chosen service can deliver, when it delivers to offices or lockers
     * @param string                  $subtotal     the items before tax and before the discount
     * @param list<CheckoutCouponView> $coupons     the codes entered, those that hold and those that do not
     * @param list<AskedField>        $fields      the questions add-ons and the shop's checkout forms ask, for this order
     */
    public function __construct(
        public array $lines,
        public bool $needsShipping,
        public array $shippingOptions,
        public ?string $shippingOption,
        public bool $needsPayment,
        public array $paymentMethods,
        public array $taxes,
        public string $subtotal,
        public string $itemsTax,
        public string $shipping,
        public string $shippingTax,
        public string $total,
        public string $currency,
        /** Whether visitors may (optional), must (required) or cannot (disabled) make an account. */
        public string $accounts,
        public array $pickupPoints = [],
        public ?string $pickupPoint = null,
        /** What coupons take off the items. */
        public string $discount = '0',
        public array $coupons = [],
        public array $fields = [],
        /** The amounts include tax: the tax lines say how much of the total is tax, they do not add to it. */
        public bool $pricesIncludeTax = false,
        /** Checkout asks business customers for their VAT number. */
        public bool $vatAsked = false,
        /** The order goes without VAT: the business customer accounts for it (reverse charge). */
        public bool $reverseCharge = false,
        /** Why VAT is charged although a VAT number was given. */
        public ?string $vatMessage = null,
        /** Without an address from the customer: the country tax and shipping are estimated for (theirs saved, or the shop's). */
        public ?string $estimatedFor = null,
        /** How much more the items would have to cost for one of the shop's rates to ship them free; null when none would. */
        public ?string $freeShippingShort = null,
        /** The way to pay the totals are for: the one chosen, or the first offered. */
        public ?string $paymentMethod = null,
        /** @var list<CheckoutFeeView> what the order pays besides its items and shipping */
        public array $fees = [],
        public string $feesTax = '0',
        /** @var array<string, string> what each way to pay adds, before tax, by gateway; those without a fee left out */
        public array $paymentFees = [],
        /** The days, and times of day, the chosen way to ship lets the customer choose; null when it offers none. */
        public ?DeliveryChoiceView $delivery = null,
        /** The day and the time of day chosen, as the form sends them back. */
        public ?string $deliveryDate = null,
        public ?string $deliverySlot = null,
        /** The shop's least order, when the items come to less after discounts, and how much more they need. */
        public ?string $minimumOrder = null,
        public ?string $minimumShort = null,
        /** When no way to ship takes the order to the address: the least order value at which one would. */
        public ?string $shipsFrom = null,
        /** @var list<CheckoutGiftCardView> the gift cards entered, and the store credit when used */
        public array $giftCards = [],
        public string $giftCardTotal = '0',
        /** What is left to pay the chosen way: the total, less the gift cards. */
        public string $amountDue = '0',
        /** What the logged-in customer has as store credit, and whether it pays. */
        public string $storeCredit = '0',
        public bool $usingStoreCredit = false,
    ) {}
}
