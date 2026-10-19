<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Payment\Application\Dto\PaymentOptionView;
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
     * @param list<CheckoutFieldView> $fields      the questions add-ons ask, for this order
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
    ) {}
}
