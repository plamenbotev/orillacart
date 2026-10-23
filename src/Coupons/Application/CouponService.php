<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application;

use OrillaCart\Coupons\Application\Dto\CouponPageView;
use OrillaCart\Coupons\Application\Dto\CouponQuery;
use OrillaCart\Coupons\Application\Dto\CouponView;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\Coupons\Domain\CouponKind;
use OrillaCart\Coupons\Domain\CouponNotFound;
use OrillaCart\Coupons\Domain\CouponRepository;
use OrillaCart\Coupons\Domain\CouponRuleViolation;
use OrillaCart\Coupons\Domain\CouponTerms;
use OrillaCart\Shared\Domain\Money;

/**
 * The coupons the shop sets up. Orders keep the code and amount they used, so changing or deleting a coupon
 * never changes a placed order.
 */
final readonly class CouponService
{
    public function __construct(private CouponRepository $coupons) {}

    public function coupons(CouponQuery $query): CouponPageView
    {
        [$coupons, $total] = $this->coupons->search($query->search, $query->page, $query->perPage, $query->automatic);

        return new CouponPageView(array_map(CouponView::fromCoupon(...), $coupons), $total, $query->page, max(1, (int) ceil($total / $query->perPage)));
    }

    /** The coupon with the code, when the shop can offer it now; null otherwise. */
    public function offerable(string $code, \DateTimeImmutable $now): ?CouponView
    {
        $coupon = $this->coupons->findByCodes([Coupon::normalize($code)])[Coupon::normalize($code)] ?? null;

        return $coupon !== null && $coupon->offerable($now) ? CouponView::fromCoupon($coupon) : null;
    }

    /**
     * @throws CouponNotFound
     */
    public function coupon(int $id): CouponView
    {
        return CouponView::fromCoupon($this->get($id));
    }

    /**
     * @throws CouponRuleViolation for terms that do not make a coupon, or a code in use
     */
    public function create(SaveCoupon $command): CouponView
    {
        $terms = self::terms($command, $command->automatic && trim($command->code) === '' ? 'AUTO-' . strtoupper(bin2hex(random_bytes(4))) : null);
        $this->requireFreeCode($terms->code, null);
        $coupon = new Coupon($terms, $command->automatic);
        $this->coupons->save($coupon);

        return CouponView::fromCoupon($coupon);
    }

    /**
     * @throws CouponNotFound
     * @throws CouponRuleViolation
     */
    public function update(int $id, SaveCoupon $command): CouponView
    {
        $coupon = $this->get($id);
        $terms = self::terms($command, $coupon->isAutomatic() && trim($command->code) === '' ? $coupon->code() : null);
        $this->requireFreeCode($terms->code, $id);
        $coupon->change($terms);
        $this->coupons->save($coupon);

        return CouponView::fromCoupon($coupon);
    }

    /**
     * @throws CouponNotFound
     */
    public function delete(int $id): void
    {
        $this->coupons->delete($this->get($id));
    }

    private function get(int $id): Coupon
    {
        return $this->coupons->find($id) ?? throw new CouponNotFound($id);
    }

    private function requireFreeCode(string $code, ?int $id): void
    {
        $existing = $this->coupons->findByCodes([Coupon::normalize($code)])[Coupon::normalize($code)] ?? null;
        if ($existing !== null && $existing->id() !== $id) {
            throw new CouponRuleViolation(\sprintf('Another coupon has the code %s.', Coupon::normalize($code)), 'orillacart_coupon_code_taken');
        }
    }

    private static function terms(SaveCoupon $command, ?string $code = null): CouponTerms
    {
        $money = static fn(?string $amount): ?Money => $amount === null || $amount === '' ? null : Money::of($amount);
        $moment = static fn(?string $at): ?\DateTimeImmutable => $at === null || $at === '' ? null : new \DateTimeImmutable($at)->setTimezone(new \DateTimeZone('UTC'));
        $ids = static fn(array $ids): array => array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));

        return new CouponTerms(
            $code ?? $command->code,
            CouponKind::from($command->kind),
            Money::of($command->amount),
            $command->description,
            $money($command->cap),
            $command->active,
            $moment($command->startsAt),
            $moment($command->endsAt),
            $money($command->minSubtotal),
            $money($command->maxSubtotal),
            $command->minQuantity,
            $command->countries,
            $ids($command->productIds),
            $ids($command->categoryIds),
            $ids($command->brandIds),
            $ids($command->excludedProductIds),
            $ids($command->excludedCategoryIds),
            $command->excludeSaleItems,
            $command->combines,
            $command->usageLimit,
            $command->perCustomerLimit,
            $command->firstOrderOnly,
            $command->buyQuantity,
            $command->getQuantity,
            $ids($command->getProductIds),
            $ids($command->getCategoryIds),
            $command->giftProductId,
            $command->overridesPriceRules,
        );
    }
}
