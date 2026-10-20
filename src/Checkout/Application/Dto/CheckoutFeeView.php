<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

/**
 * A fee in the checkout's totals, such as for the way to pay: before tax, or including it when prices do.
 */
final readonly class CheckoutFeeView
{
    public function __construct(
        public string $label,
        public string $amount,
    ) {}
}
