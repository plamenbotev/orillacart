<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Ordering\Application\Dto\OrderDiscountView;
use OrillaCart\Payment\Application\Dto\PaymentOptionView;
use OrillaCart\Shipping\Application\Dto\PickupPointView;

/**
 * An order the shop is taking, priced: its lines, the ways it can ship (the chosen one, or the first, counted in
 * the totals) and be paid.
 */
final readonly class OrderQuoteView
{
    /**
     * @param list<CheckoutLineView>   $lines
     * @param list<ShippingChoiceView> $shippingOptions
     * @param list<PaymentOptionView>  $paymentOptions
     * @param list<CheckoutTaxView>    $taxes
     * @param list<PickupPointView>    $pickupPoints where the chosen service can deliver, when it delivers to offices or lockers
     * @param list<OrderDiscountView>  $discounts    the shop's automatic discounts that hold for the order
     * @param list<CheckoutFeeView>    $fees         what it pays besides its items and shipping
     */
    public function __construct(
        public array $lines,
        public bool $needsShipping,
        public array $shippingOptions,
        public ?string $shippingOption,
        public array $paymentOptions,
        public array $taxes,
        public string $subtotal,
        public string $itemsTax,
        public string $shipping,
        public string $shippingTax,
        public string $total,
        public string $currency,
        public array $pickupPoints = [],
        public string $discount = '0',
        public array $discounts = [],
        public array $fees = [],
        public string $feesTax = '0',
        /** Sold under the reverse charge to a business with a VAT number VIES confirms. */
        public bool $reverseCharge = false,
        /** Why a VAT number given does not take the reverse charge, when it does not. */
        public ?string $vatMessage = null,
    ) {}
}
