<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Coupons\Application\Dto\PromotionView;
use OrillaCart\Coupons\Application\Promotions;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\TaxAddress;

/**
 * The promotions the shopper's cart gets and those it could still get, priced as the cart page prices it: at
 * their saved address or the shop's, in their currency, with the codes in the cart. None for an empty cart, or
 * one the shop refuses as it is.
 */
final readonly class PromotionProgress
{
    public function __construct(
        private CheckoutService $checkout,
        private CartService $carts,
        private OrderPricing $pricing,
        private Promotions $promotions,
        private Currencies $currencies,
    ) {}

    /**
     * @return list<PromotionView>
     */
    public function of(CartOwner $owner): array
    {
        $contents = $this->carts->contents($owner);
        if ($contents === null || $contents->refusals !== []) {
            return [];
        }
        $at = $this->checkout->estimateAt($owner);
        $address = $at === null ? null : new TaxAddress($at['country'], $at['state'], $at['postcode'], $at['city']);
        $currency = $this->currencies->current();
        try {
            $priced = $this->pricing->price($contents->lines, $address, $address, null, $at['postcode'] ?? null, $contents->coupons, $owner->customerId, currency: $currency);
        } catch (RuleViolation) {
            // An item no longer sold, or not in that quantity: the cart page says why.
            return [];
        }

        return $this->promotions->forCart($this->pricing->discountRequest($priced, $address?->country, $owner->customerId, $currency), $priced['discounts'], $contents->declined);
    }
}
