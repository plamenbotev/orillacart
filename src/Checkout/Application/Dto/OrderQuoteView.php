<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Payment\Application\Dto\PaymentOptionView;

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
     */
    public function __construct(
        public array $lines,
        public bool $needsShipping,
        public array $shippingOptions,
        public ?int $rateId,
        public array $paymentOptions,
        public array $taxes,
        public string $subtotal,
        public string $itemsTax,
        public string $shipping,
        public string $shippingTax,
        public string $total,
        public string $currency,
    ) {}
}
