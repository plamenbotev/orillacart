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
        /** How many, for a charge so much each (a deposit for six bottles); 1 for a fee. */
        public int $quantity = 1,
        /** Shown in the cart as a line of its own; a charge always is, as one of the cart's lines. */
        public bool $asLine = false,
        /** A charge: a cart line of its own already, not to show again. */
        public bool $charge = false,
    ) {}
}
