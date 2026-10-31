<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application;

use OrillaCart\Coupons\Application\Dto\AppliedCoupon;
use OrillaCart\Coupons\Application\Dto\AppliedDiscounts;
use OrillaCart\Coupons\Application\Dto\DiscountItem;
use OrillaCart\Coupons\Application\Dto\DiscountRequest;
use OrillaCart\Coupons\Application\Dto\PromotionView;
use OrillaCart\Coupons\Domain\Basket;
use OrillaCart\Coupons\Domain\BasketLine;
use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\Coupons\Domain\CouponKind;
use OrillaCart\Coupons\Domain\CouponProgress;
use OrillaCart\Coupons\Domain\CouponRepository;
use OrillaCart\Coupons\Domain\RedemptionRepository;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Translations\Application\ContentTranslations;
use Psr\Clock\ClockInterface;

/**
 * The promotions shoppers are told of: the coupons shown to them (automatic discounts unless the shop hides one,
 * codes the shop chose to show), for a cart or a product. Only those the shopper could still get: begun, not
 * ended or used up, for their country when known, and those for some customers only (a first order, so many uses
 * each) once it is known who shops and that they may. What a cart lacks is measured by the coupon's own rules
 * (Coupon::progress()), so it never disagrees with what checkout applies.
 */
final readonly class Promotions
{
    public function __construct(
        private CouponRepository $coupons,
        private RedemptionRepository $redemptions,
        private OrderService $orders,
        private ContentTranslations $texts,
        private ClockInterface $clock,
    ) {}

    /**
     * For a cart priced as checkout prices it: the promotions it gets, then those it could still get, the nearest
     * first. One that would not go with those it gets (it does not combine) is left out, as checkout would leave
     * it out; a code in the cart that does not hold too, as the cart says why. One that gives items the cart could
     * not be given (none left in stock) takes nothing off, and is left out too.
     *
     * @param list<string> $declined the coupons whose items the shopper removed, by code: they may have them back
     *
     * @return list<PromotionView>
     */
    public function forCart(DiscountRequest $request, AppliedDiscounts $applied, array $declined = []): array
    {
        $advertised = $request->items === [] ? [] : $this->coupons->advertised();
        if ($advertised === []) {
            return [];
        }
        $known = ($request->email ?? '') !== '' || $request->customerId !== null;
        $lines = array_map(static fn(DiscountItem $item): BasketLine => new BasketLine($item->productId, $item->quantity, Money::of($item->total), $item->onSale, $item->categoryIds, $item->brandIds, $item->coupons), $request->items);
        $basket = new Basket(
            $lines,
            $request->currency,
            $request->country,
            $request->customerId,
            $request->email,
            $this->firstOrder($advertised, $known, $request->customerId, $request->email ?? '', $request->orderId),
            null,
            $request->rate,
        );
        $got = [];
        foreach ($applied->coupons as $coupon) {
            $got[$coupon->code] = $coupon;
        }
        $automatic = $this->coupons->automatic();
        $codes = array_values(array_filter($applied->coupons, static fn(AppliedCoupon $coupon): bool => !$coupon->automatic));
        $enteredCodes = $codes === [] ? [] : array_values($this->coupons->findByCodes(array_map(static fn(AppliedCoupon $coupon): string => $coupon->code, $codes)));
        $words = $this->descriptions($advertised);
        $now = $this->clock->now();

        $gets = [];
        $offers = [];
        foreach ($advertised as $coupon) {
            $done = $got[$coupon->code()] ?? null;
            if ($done !== null) {
                $refused = \in_array($coupon->code(), $declined, true);
                if ($refused || !$coupon->givesItems() || !Money::of($done->amount)->isZero()) {
                    $gets[] = $this->view($coupon, $basket, $words, applied: $done, declined: $refused);
                }
                continue;
            }
            if (isset($applied->refused[$coupon->code()]) || self::blocked($coupon, $automatic, $got, $enteredCodes) || !$this->obtainable($coupon, $basket, $known, $now)) {
                continue;
            }
            $progress = $coupon->progress($basket);
            // An automatic discount the cart has all it needs for applies already, or gives nothing (an amount
            // off items already free): nothing to tell of. A code still has to be entered.
            if ($progress === null || ($progress->holds() && $coupon->isAutomatic())) {
                continue;
            }
            $offers[] = $this->view($coupon, $basket, $words, progress: $progress);
        }
        usort($offers, static fn(PromotionView $a, PromotionView $b): int => $b->nearness <=> $a->nearness);

        return [...$gets, ...$offers];
    }

    /**
     * For a product's page or card: the promotions that apply to it, as they would in a cart. Those for some
     * customers only are for a known shopper who may have them; a visitor's page, which caches keep, has only
     * those that hold whoever shops.
     *
     * @param DiscountItem $item     one of the product, at its price in the currency
     * @param string|null  $country  the shopper's, from their saved address, else the shop's
     * @param string       $rate     units of the currency for one of the shop's
     *
     * @return list<PromotionView>
     */
    public function forProduct(DiscountItem $item, string $currency, string $rate = '1', ?string $country = null, ?int $customerId = null): array
    {
        $advertised = $this->coupons->advertised();
        if ($advertised === []) {
            return [];
        }
        $line = new BasketLine($item->productId, 1, Money::of($item->total), $item->onSale, $item->categoryIds, $item->brandIds, $item->coupons);
        $known = $customerId !== null;
        $basket = new Basket([$line], $currency, $country, $customerId, null, $this->firstOrder($advertised, $known, $customerId, ''), null, $rate);
        $words = $this->descriptions($advertised);
        $now = $this->clock->now();
        $views = [];
        foreach ($advertised as $coupon) {
            // progress() is null for one this item alone is already too large an order for.
            if ($coupon->appliesTo($line) && $this->obtainable($coupon, $basket, $known, $now) && $coupon->progress($basket) !== null) {
                $views[] = $this->view($coupon, $basket, $words);
            }
        }

        return $views;
    }

    /**
     * Whether the shopper could still have it: one for some customers only waits to know who shops.
     */
    private function obtainable(Coupon $coupon, Basket $basket, bool $known, \DateTimeImmutable $now): bool
    {
        if ($coupon->dependsOnWho() && !$known) {
            return false;
        }
        $uses = $known && $coupon->terms()->perCustomerLimit !== null ? $this->redemptions->countByCustomer($coupon->id(), $basket->customerId, $basket->email ?? '') : 0;

        return $coupon->obtainable($basket, $now, $uses);
    }

    /**
     * Whether the shopper never ordered before, asked only when a coupon shown is for a first order.
     *
     * @param list<Coupon> $coupons
     */
    private function firstOrder(array $coupons, bool $known, ?int $customerId, string $email, ?int $orderId = null): bool
    {
        if (!$known || !array_any($coupons, static fn(Coupon $coupon): bool => $coupon->terms()->firstOrderOnly)) {
            return true;
        }

        return !$this->orders->hasOrdered($customerId, $email, $orderId);
    }

    /**
     * Whether checkout would leave the coupon out beside those the cart gets, as Discounts::apply() goes through
     * them: automatic discounts oldest first, then codes; one that does not combine stands alone among its kind,
     * and a code that does not combine is refused beside an automatic discount.
     *
     * @param list<Coupon>                 $automatic    the shop's, oldest first
     * @param array<string, AppliedCoupon> $got          what the cart gets, by code
     * @param list<Coupon>                 $enteredCodes the codes in the cart that apply
     */
    private static function blocked(Coupon $coupon, array $automatic, array $got, array $enteredCodes): bool
    {
        $gotAutomatic = array_values(array_filter($automatic, static fn(Coupon $other): bool => isset($got[$other->code()])));
        if (!$coupon->isAutomatic()) {
            return ($enteredCodes !== [] && (!$coupon->combines() || array_any($enteredCodes, static fn(Coupon $other): bool => !$other->combines())))
                || ($gotAutomatic !== [] && !$coupon->combines());
        }
        // Those before it decide: once it applies, it takes the place of those after it that do not combine.
        $before = [];
        foreach ($automatic as $other) {
            if ($other->code() === $coupon->code()) {
                break;
            }
            if (isset($got[$other->code()])) {
                $before[] = $other;
            }
        }

        return $before !== [] && (!$coupon->combines() || array_any($before, static fn(Coupon $other): bool => !$other->combines()));
    }

    /**
     * @param list<Coupon> $coupons
     *
     * @return array<int, string> the descriptions in the language shown, by coupon id; those translated
     */
    private function descriptions(array $coupons): array
    {
        return $this->texts->translated(CouponTranslations::TYPE, array_map(static fn(Coupon $coupon): int => $coupon->id(), $coupons), 'description');
    }

    /**
     * @param array<int, string> $words translated descriptions, by coupon id
     */
    private function view(Coupon $coupon, Basket $basket, array $words, ?AppliedCoupon $applied = null, ?CouponProgress $progress = null, bool $declined = false): PromotionView
    {
        $terms = $coupon->terms();
        // A percentage is the same in any currency; an amount is the shop's.
        $amount = match ($terms->kind) {
            CouponKind::OrderAmount, CouponKind::ItemAmount => (string) $basket->inCurrency($terms->amount),
            CouponKind::FreeShipping, CouponKind::FreeGift => '0',
            default => (string) $terms->amount,
        };

        return new PromotionView(
            $coupon->code(),
            $coupon->isAutomatic(),
            $terms->kind->value,
            $words[$coupon->id()] ?? $coupon->description(),
            $amount,
            $basket->currency,
            $terms->minSubtotal === null ? null : (string) $basket->inCurrency($terms->minSubtotal),
            $terms->minQuantity,
            $terms->buyQuantity,
            $terms->getQuantity,
            $terms->productIds,
            $terms->categoryIds,
            $terms->brandIds,
            $terms->getProductIds,
            $terms->getCategoryIds,
            $terms->giftProductId,
            $applied !== null,
            $applied === null ? null : (string) Money::of($applied->amount),
            $progress === null ? null : (string) $progress->spent,
            $progress?->short() === null ? null : (string) $progress->short(),
            $progress->units ?? 0,
            $progress->neededUnits ?? 0,
            $progress->needsGiven ?? false,
            $progress?->nearness() ?? 1.0,
            $declined,
        );
    }
}
