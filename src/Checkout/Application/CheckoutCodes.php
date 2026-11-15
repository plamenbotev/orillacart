<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartContents;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CheckoutView;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Discounts;
use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\GiftCards\Application\Dto\GiftCardCodeInput;
use OrillaCart\GiftCards\Application\GiftCardPayments;
use OrillaCart\GiftCards\Domain\GiftCardRuleViolation;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\Persistence\Transactions;

/**
 * The codes a shopper enters at checkout: coupons, once they hold for the cart, and gift cards, once they can pay;
 * and their store credit. Each answers with the checkout as it now is.
 */
final readonly class CheckoutCodes
{
    public function __construct(
        private CartService $carts,
        private OrderPricing $pricing,
        private GiftCardPayments $giftCards,
        private Currencies $currencies,
        private CheckoutService $checkout,
        private Transactions $transactions,
    ) {}

    /**
     * One change to the cart's codes, by what the cart holds: read under the cart's lock and saved in one
     * transaction (PLAN 732), then the checkout as it now is.
     *
     * @param \Closure(CartContents): void $change
     *
     * @throws CheckoutRuleViolation for an empty cart, or what the change refuses
     */
    private function change(CartOwner $owner, \Closure $change): CheckoutView
    {
        $this->transactions->run(function () use ($owner, $change): void {
            $change($this->checkout->claimedContents($owner));
        });

        return $this->checkout->summary($owner, new CheckoutDetails());
    }

    /**
     * Enters a gift card code for the order, once it is one that can pay.
     *
     * @throws CheckoutRuleViolation for an empty cart, or a code that cannot pay
     */
    public function applyGiftCard(CartOwner $owner, GiftCardCodeInput $command): CheckoutView
    {
        return $this->change($owner, function (CartContents $contents) use ($command): void {
            $code = strtoupper(trim($command->code));
            try {
                $balance = $this->giftCards->balance($code, $this->currencies->current());
            } catch (GiftCardRuleViolation $e) {
                throw new CheckoutRuleViolation($e->text, 'orillacart_gift_card_refused', $e);
            }
            if ($balance->refused !== null) {
                throw new CheckoutRuleViolation($balance->refused, 'orillacart_gift_card_refused');
            }
            if (!\in_array($code, $contents->giftCards, true) && \count($contents->giftCards) >= GiftCardPayments::MAX_CODES) {
                throw new CheckoutRuleViolation('An order takes up to %d gift cards.', 'orillacart_gift_card_refused', values: [GiftCardPayments::MAX_CODES]);
            }
            $this->carts->useGiftCards($contents->cartId, array_values(array_unique([...$contents->giftCards, $code])), $contents->useStoreCredit);
        });
    }

    /**
     * @throws CheckoutRuleViolation for an empty cart
     */
    public function removeGiftCard(CartOwner $owner, string $code): CheckoutView
    {
        return $this->change($owner, function (CartContents $contents) use ($code): void {
            $code = strtoupper(trim($code));
            $this->carts->useGiftCards($contents->cartId, array_values(array_filter($contents->giftCards, static fn(string $kept): bool => $kept !== $code)), $contents->useStoreCredit);
        });
    }

    /**
     * The logged-in customer pays with their store credit, as far as it goes, or stops.
     *
     * @throws CheckoutRuleViolation for an empty cart, or a visitor who is not logged in
     */
    public function useStoreCredit(CartOwner $owner, bool $use): CheckoutView
    {
        if ($owner->customerId === null) {
            throw new CheckoutRuleViolation('Log in to use your store credit.', 'orillacart_store_credit_login');
        }
        return $this->change($owner, function (CartContents $contents) use ($use): void {
            $this->carts->useGiftCards($contents->cartId, $contents->giftCards, $use);
        });
    }

    /**
     * Adds a coupon code to the cart when it holds for what the cart has; rules about the address or the customer
     * wait for checkout.
     *
     * @throws CheckoutRuleViolation for an empty cart, or a code that does not hold, saying why
     */
    public function applyCoupon(CartOwner $owner, CouponCode $command): CheckoutView
    {
        return $this->change($owner, function (CartContents $contents) use ($owner, $command): void {
            $code = Coupon::normalize($command->code);
            $held = array_map(Coupon::normalize(...), $contents->coupons);
            if (!\in_array($code, $held, true) && \count($held) >= Discounts::MAX_CODES) {
                throw new CheckoutRuleViolation('An order takes up to %d codes.', 'orillacart_coupon_refused', values: [Discounts::MAX_CODES]);
            }
            $codes = array_values(array_unique([...$held, $code]));
            $priced = $this->pricing->price($contents->lines, null, null, null, null, $codes, $owner->customerId, currency: $this->currencies->current());
            $refused = $priced['discounts']->refused[$code] ?? null;
            if ($refused !== null) {
                throw new CheckoutRuleViolation($refused, 'orillacart_coupon_refused');
            }
            $this->carts->useCoupons($contents->cartId, $codes);
        });
    }

    /**
     * @throws CheckoutRuleViolation for an empty cart
     */
    public function removeCoupon(CartOwner $owner, string $code): CheckoutView
    {
        return $this->change($owner, function (CartContents $contents) use ($code): void {
            $code = Coupon::normalize($code);
            $this->carts->useCoupons($contents->cartId, array_values(array_filter(array_map(Coupon::normalize(...), $contents->coupons), static fn(string $kept): bool => $kept !== $code)));
        });
    }
}
