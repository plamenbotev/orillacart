<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Payment\Application\Dto\PaymentOptionView;

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
     */
    public function __construct(
        public array $lines,
        public bool $needsShipping,
        public array $shippingOptions,
        public ?int $rateId,
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
    ) {}
}
