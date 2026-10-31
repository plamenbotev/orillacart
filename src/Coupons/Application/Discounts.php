<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application;

use OrillaCart\Coupons\Application\Dto\AppliedCoupon;
use OrillaCart\Coupons\Application\Dto\AppliedDiscounts;
use OrillaCart\Coupons\Application\Dto\DiscountItem;
use OrillaCart\Coupons\Application\Dto\DiscountRequest;
use OrillaCart\Coupons\Domain\Basket;
use OrillaCart\Coupons\Domain\BasketLine;
use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\Coupons\Domain\CouponRedemption;
use OrillaCart\Coupons\Domain\CouponRepository;
use OrillaCart\Coupons\Domain\CouponRuleViolation;
use OrillaCart\Coupons\Domain\RedemptionRepository;
use OrillaCart\Ordering\Application\Dto\OrderDiscountView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Translations\Application\ContentTranslations;
use Psr\Clock\ClockInterface;

/**
 * Coupon codes as checkout uses them: which hold for an order and what they take off each item, and the uses
 * an order makes of them. Automatic discounts come first, wherever they hold, and need no code. Each coupon takes off what the ones before it left, so items never go below zero.
 * A coupon's description is the shopper's language's, where it is translated (design 51).
 * Uses are counted when the order is placed, under a lock on the coupons, so a limit holds for orders placed at
 * the same moment; an order that is cancelled or fails gives its uses back.
 */
final readonly class Discounts
{
    /** Codes an order carries at most. */
    public const int MAX_CODES = 10;

    public function __construct(
        private CouponRepository $coupons,
        private RedemptionRepository $redemptions,
        private OrderService $orders,
        private ClockInterface $clock,
        private ContentTranslations $texts,
    ) {}

    /**
     * @param list<string> $codes as the customer entered them
     */
    public function apply(array $codes, DiscountRequest $request): AppliedDiscounts
    {
        $codes = \array_slice(array_values(array_unique(array_filter(array_map(Coupon::normalize(...), $codes), static fn(string $code): bool => $code !== ''))), 0, self::MAX_CODES);
        if ($request->items === []) {
            return new AppliedDiscounts([], array_fill_keys($codes, new Text('Add items first.')));
        }
        $automatic = $request->automatic === null ? $this->coupons->automatic() : array_values(array_filter($this->coupons->automatic(), static fn(Coupon $coupon): bool => \in_array($coupon->code(), $request->automatic, true)));
        $found = $codes === [] ? [] : $this->coupons->findByCodes($codes);
        if ($automatic === [] && $found === [] && $codes === []) {
            return new AppliedDiscounts();
        }
        $now = $this->clock->now();
        $words = $this->texts->translated(CouponTranslations::TYPE, array_map(static fn(Coupon $coupon): int => $coupon->id(), [...$automatic, ...array_values($found)]), 'description');
        $email = $request->email ?? '';
        $firstOrder = null;
        $left = array_map(static fn(DiscountItem $item): Money => Money::of($item->total), $request->items);
        // Order size rules look at the items as they cost, before any discount.
        $subtotal = array_reduce($left, static fn(Money $sum, Money $total): Money => $sum->add($total), Money::zero());
        $known = $email !== '' || $request->customerId !== null;
        $applied = [];
        $refused = [];
        $gifts = [];
        // Automatic discounts always go with codes: a customer never loses one by entering a code. Among
        // themselves, and codes among themselves, one that does not combine stands alone.
        $automaticApplied = [];
        $codesApplied = false;
        $automaticCombine = true;
        $codesCombine = true;

        $candidates = [];
        foreach ($automatic as $coupon) {
            $candidates[] = [$coupon->code(), $coupon];
        }
        foreach ($codes as $code) {
            // An automatic discount has no code to enter.
            $candidates[] = [$code, ($found[$code] ?? null)?->isAutomatic() === false ? $found[$code] : null];
        }
        foreach ($candidates as [$code, $coupon]) {
            $silent = $coupon?->isAutomatic() === true;
            if ($coupon === null) {
                $refused[$code] = new Text('This code is not valid.');
                continue;
            }
            if ($silent && $automaticApplied !== [] && (!$coupon->combines() || !$automaticCombine)) {
                continue;
            }
            if (!$silent && $codesApplied && (!$coupon->combines() || !$codesCombine)) {
                $refused[$code] = new Text('This code cannot be used together with your other codes.');
                continue;
            }
            if (!$silent && $automaticApplied !== [] && !$coupon->combines()) {
                $refused[$code] = Text::of('This code cannot be combined with %s.', implode(', ', $automaticApplied));
                continue;
            }
            if ($silent && !$known && $coupon->dependsOnWho()) {
                // A first-order or once-each discount waits for the email: shown before it, the order could cost
                // more than the summary said once a returning customer gives theirs. Given at placing, it costs less.
                continue;
            }
            $firstOrder ??= !$known || !$this->orders->hasOrdered($request->customerId, $email, $request->orderId);
            $basket = new Basket(
                array_map(static fn(DiscountItem $item, Money $total): BasketLine => new BasketLine($item->productId, $item->quantity, $total, $item->onSale, $item->categoryIds, $item->brandIds, $item->coupons), $request->items, $left),
                $request->currency,
                $request->country,
                $request->customerId,
                $request->email,
                $firstOrder,
                $subtotal,
                $request->rate,
            );
            $refusal = $coupon->refusal($basket, $now, $known ? $this->redemptions->countByCustomer($coupon->id(), $request->customerId, $email) : 0);
            if ($refusal !== null) {
                if (!$silent) {
                    $refused[$code] = $refusal;
                }
                continue;
            }
            $lines = [];
            $sum = Money::zero();
            foreach ($coupon->discounts($basket) as $index => $discount) {
                $left[$index] = $left[$index]->subtract($discount);
                $lines[$index] = $discount->toFixed();
                $sum = $sum->add($discount);
            }
            $missing = $coupon->missingGift($basket);
            if ($silent && $sum->isZero() && !$coupon->freeShipping() && $missing === null) {
                continue;
            }
            if ($missing !== null) {
                $gifts[] = $missing;
            }
            $description = $words[$coupon->id()] ?? $coupon->description();
            $applied[] = new AppliedCoupon($coupon->code(), $description, $sum->toFixed(), $coupon->freeShipping(), $lines, $silent, $coupon->freeShippingEveryRate());
            if ($silent) {
                $automaticApplied[] = $description === '' ? $coupon->code() : $description;
                $automaticCombine = $automaticCombine && $coupon->combines();
            } else {
                $codesApplied = true;
                $codesCombine = $codesCombine && $coupon->combines();
            }
        }

        return new AppliedDiscounts($applied, $refused, $gifts);
    }

    /**
     * The automatic discounts that hold whoever orders: not those for first orders or once per customer.
     *
     * @return list<string> their codes
     */
    public function automaticForAnyone(): array
    {
        return array_values(array_map(static fn(Coupon $coupon): string => $coupon->code(), array_filter($this->coupons->automatic(), static fn(Coupon $coupon): bool => !$coupon->dependsOnWho())));
    }

    /**
     * Counts the order's uses of its coupons. Run it in the transaction that saves the order.
     *
     * @param list<AppliedCoupon> $coupons
     *
     * @throws CouponRuleViolation when a coupon was used up by another order in the meantime
     */
    public function redeem(int $orderId, array $coupons, ?int $customerId, string $email): void
    {
        if ($coupons === []) {
            return;
        }
        $codes = array_map(static fn(AppliedCoupon $coupon): string => $coupon->code, $coupons);
        $firstOrderIds = $this->coupons->firstOrderOnlyIds();
        $firstOrder = array_filter($this->coupons->findByCodes($codes), static fn(Coupon $coupon): bool => \in_array($coupon->id(), $firstOrderIds, true)) !== [];
        if ($firstOrder) {
            // Before the coupons' locks, as every order takes them: the customer's first orders one at a time.
            $this->redemptions->takeFirstOrderTurn($customerId, $email);
        }
        $locked = $this->coupons->findByCodes($codes, true);
        // Counted before this order's own uses: of two first orders placed at once, the one that counted its use
        // first is the first order, whichever code each used.
        $ordered = $firstOrder && ($this->redemptions->countByCustomer($firstOrderIds, $customerId, $email, true) > 0 || $this->orders->hasOrdered($customerId, $email, $orderId));
        foreach ($coupons as $applied) {
            $coupon = $locked[$applied->code] ?? throw new CouponRuleViolation('The code %s is no longer valid.', 'orillacart_coupon_refused', values: [$applied->code]);
            if ($this->usedUp($coupon, $customerId, $email)) {
                throw new CouponRuleViolation('The code %s was just used up. Remove it and try again.', 'orillacart_coupon_used_up', values: [$coupon->code()]);
            }
            if ($coupon->terms()->firstOrderOnly && $ordered) {
                throw new CouponRuleViolation('The code %s is for a first order only. Remove it and try again.', 'orillacart_coupon_used_up', values: [$coupon->code()]);
            }
            $coupon->use();
            $this->coupons->save($coupon);
            $this->redemptions->save(new CouponRedemption($coupon->id(), $orderId, $customerId, $email, Money::of($applied->amount), $this->clock->now()));
        }
    }

    /**
     * Counts again the uses of an order that was cancelled or failed and opened again. Paid, the customer paid the
     * discounted price, so the uses count even past a limit reached in the meantime; to be paid now, as Pay now
     * opens a failed order, each coupon must still have a use left for it.
     *
     * @throws OrderNotFound
     * @throws CouponRuleViolation for an order to be paid now whose coupon was used up since
     */
    public function reclaim(int $orderId): void
    {
        if ($this->redemptions->ofOrder($orderId) !== []) {
            return;
        }
        $order = $this->orders->order($orderId);
        $locked = $this->coupons->findByCodes(array_map(static fn(OrderDiscountView $discount): string => $discount->code, $order->discounts), true);
        foreach ($order->discounts as $discount) {
            $coupon = $locked[$discount->code] ?? null;
            if ($coupon === null) {
                // Deleted since: nothing left to count.
                continue;
            }
            if ($order->paidAt === null && $this->usedUp($coupon, $order->customerId, $order->email)) {
                throw new CouponRuleViolation('The code %s was used up since this order failed, so it cannot be paid now. Order again from the shop.', 'orillacart_coupon_used_up', values: [$coupon->code()]);
            }
            $coupon->use();
            $this->coupons->save($coupon);
            $this->redemptions->save(new CouponRedemption($coupon->id(), $orderId, $order->customerId, $order->email, Money::of($discount->amount), $this->clock->now()));
        }
    }

    /**
     * Whether a locked coupon has no use left: in all, or for this customer, counted as they stand now.
     */
    private function usedUp(Coupon $coupon, ?int $customerId, string $email): bool
    {
        $terms = $coupon->terms();

        return $terms->usageLimit !== null && $coupon->uses() >= $terms->usageLimit
            || $terms->perCustomerLimit !== null && $this->redemptions->countByCustomer($coupon->id(), $customerId, $email, true) >= $terms->perCustomerLimit;
    }

    /**
     * Gives back the uses an order made: it was cancelled, failed, or is priced again.
     */
    public function release(int $orderId): void
    {
        $redemptions = $this->redemptions->ofOrder($orderId);
        if ($redemptions === []) {
            return;
        }
        $codes = [];
        foreach ($redemptions as $redemption) {
            $coupon = $this->coupons->find($redemption->couponId());
            if ($coupon !== null) {
                $codes[$coupon->id()] = $coupon->code();
            }
        }
        // Locked, as redeem() locks them, so a use counted at the same moment is not lost.
        $locked = $this->coupons->findByCodes(array_values($codes), true);
        foreach ($redemptions as $redemption) {
            $locked[$codes[$redemption->couponId()] ?? '']?->release();
            $this->redemptions->delete($redemption);
        }
        foreach ($locked as $coupon) {
            $this->coupons->save($coupon);
        }
    }
}
