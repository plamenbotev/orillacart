<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\FreeShippingView;
use OrillaCart\Checkout\Application\Dto\ShippingChoiceView;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * How far the shopper's cart is from free shipping, priced as the cart page prices it: at their saved address or
 * the shop's, in their currency, after the codes in the cart. Nothing for a cart that ships nothing, cannot be
 * priced, or that no free rate would take.
 */
final readonly class FreeShippingProgress
{
    public function __construct(private CheckoutService $checkout) {}

    public function of(CartOwner $owner): ?FreeShippingView
    {
        try {
            $summary = $this->checkout->summary($owner, new CheckoutDetails());
        } catch (RuleViolation) {
            // An empty cart, or one the shop refuses as it is: the cart page says why.
            return null;
        }
        if (!$summary->needsShipping) {
            return null;
        }
        if ($summary->freeShippingShort !== null && $summary->freeShippingFrom !== null) {
            $threshold = Money::of($summary->freeShippingFrom);
            $remaining = Money::of($summary->freeShippingShort);

            return new FreeShippingView(false, (string) $threshold->subtract($remaining), (string) $threshold, (string) $remaining, $summary->currency);
        }
        // A delivery that costs nothing (collecting from the shop is no delivery): a free rate, or a code's free shipping.
        $free = array_any($summary->shippingOptions, static fn(ShippingChoiceView $option): bool => $option->collectAt === null && Money::of($option->total)->isZero());

        return $free ? new FreeShippingView(true, null, null, null, $summary->currency) : null;
    }
}
