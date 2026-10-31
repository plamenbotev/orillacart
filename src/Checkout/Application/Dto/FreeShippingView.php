<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

/**
 * How far the cart is from shipping free: "spend 12.40 more", or "it ships free". The amounts are in the
 * shopper's currency, the items after discounts and before tax, as the shop's rates look at them.
 */
final readonly class FreeShippingView
{
    public function __construct(
        /** One of the shop's ways to ship the cart costs nothing now. */
        public bool $reached,
        /** What the items come to now; null once reached. */
        public ?string $spent,
        /** The order value from which it would ship free; null once reached. */
        public ?string $threshold,
        /** How much more it needs; null once reached. */
        public ?string $remaining,
        public string $currency,
    ) {}
}
