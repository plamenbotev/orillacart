<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application;

use OrillaCart\Catalog\Application\ProductTranslations;
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
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Translations\Application\ContentTranslations;
use OrillaCart\Translations\Application\TranslationPackages;
use OrillaCart\Translations\Domain\TranslationRuleViolation;

/**
 * The coupons the shop sets up. Orders keep the code and amount they used, so changing or deleting a coupon
 * never changes a placed order. Its description, which shoppers read, is translated (design 51).
 */
final readonly class CouponService
{
    public function __construct(
        private CouponRepository $coupons,
        private ProductTranslations $translations,
        private ContentTranslations $texts,
        private Transactions $transactions,
        private TranslationPackages $packages,
    ) {}

    public function coupons(CouponQuery $query): CouponPageView
    {
        [$coupons, $total] = $this->coupons->search($query->search, $query->page, $query->perPage, $query->automatic);

        $translations = $this->texts->of(CouponTranslations::TYPE, array_map(static fn(Coupon $coupon): int => $coupon->id(), $coupons));

        return new CouponPageView(array_map(static fn(Coupon $coupon): CouponView => CouponView::fromCoupon($coupon, $translations[$coupon->id()] ?? []), $coupons), $total, $query->page, max(1, (int) ceil($total / $query->perPage)));
    }

    /** The coupon with the code, when the shop can offer it now; null otherwise. */
    public function offerable(string $code, \DateTimeImmutable $now): ?CouponView
    {
        $coupon = $this->coupons->findByCodes([Coupon::normalize($code)])[Coupon::normalize($code)] ?? null;

        return $coupon !== null && $coupon->offerable($now) ? $this->view($coupon) : null;
    }

    /**
     * @throws CouponNotFound
     */
    public function coupon(int $id): CouponView
    {
        return $this->view($this->get($id));
    }

    /**
     * @throws CouponRuleViolation      for terms that do not make a coupon, or a code in use
     * @throws TranslationRuleViolation
     */
    public function create(SaveCoupon $command): CouponView
    {
        $texts = $this->checkedTexts($command);
        $terms = $this->terms($command, $command->automatic && trim($command->code) === '' ? 'AUTO-' . strtoupper(bin2hex(random_bytes(4))) : null);
        $this->requireFreeCode($terms->code, null);

        return $this->saved(new Coupon($terms, $command->automatic, $command->advertised), $texts);
    }

    /**
     * @throws CouponNotFound
     * @throws CouponRuleViolation
     * @throws TranslationRuleViolation
     */
    public function update(int $id, SaveCoupon $command): CouponView
    {
        $coupon = $this->get($id);
        $texts = $this->checkedTexts($command);
        $terms = $this->terms($command, $coupon->isAutomatic() && trim($command->code) === '' ? $coupon->code() : null);
        $this->requireFreeCode($terms->code, $id);
        $coupon->change($terms);
        $coupon->advertise($command->advertised);

        return $this->saved($coupon, $texts);
    }

    /**
     * @throws CouponNotFound
     */
    public function delete(int $id): void
    {
        $coupon = $this->get($id);
        $this->transactions->run(function () use ($coupon, $id): void {
            $this->texts->forget(CouponTranslations::TYPE, [$id]);
            $this->coupons->delete($coupon);
        });
        $this->packages->removed(CouponPackages::KIND, $id);
    }

    /**
     * The description's translations, checked before anything changes; null to keep them as they are.
     *
     * @return array<string, array<string, string>>|null
     *
     * @throws TranslationRuleViolation
     */
    private function checkedTexts(SaveCoupon $command): ?array
    {
        return $command->translations === null ? null : $this->texts->check(CouponTranslations::TYPE, $command->translations);
    }

    /**
     * The coupon and its description's translations together, or neither; a new coupon has its id once saved.
     *
     * @param array<string, array<string, string>>|null $texts
     */
    private function saved(Coupon $coupon, ?array $texts): CouponView
    {
        $this->transactions->run(function () use ($coupon, $texts): void {
            $this->coupons->save($coupon);
            if ($texts !== null) {
                $this->texts->write(CouponTranslations::TYPE, $coupon->id(), $texts);
            }
        });
        $this->packages->changed(CouponPackages::KIND, $coupon->id());

        return $this->view($coupon);
    }

    private function view(Coupon $coupon): CouponView
    {
        return CouponView::fromCoupon($coupon, $this->texts->of(CouponTranslations::TYPE, [$coupon->id()])[$coupon->id()] ?? []);
    }

    private function get(int $id): Coupon
    {
        return $this->coupons->find($id) ?? throw new CouponNotFound($id);
    }

    private function requireFreeCode(string $code, ?int $id): void
    {
        $existing = $this->coupons->findByCodes([Coupon::normalize($code)])[Coupon::normalize($code)] ?? null;
        if ($existing !== null && $existing->id() !== $id) {
            throw new CouponRuleViolation('Another coupon has the code %s.', 'orillacart_coupon_code_taken', values: [Coupon::normalize($code)]);
        }
    }

    private function terms(SaveCoupon $command, ?string $code = null): CouponTerms
    {
        // Products picked in the admin's language are their originals, the products (design 51).
        $products = fn(array $ids): array => $this->translations->originals(array_values(array_map(intval(...), array_filter($ids, is_numeric(...)))));
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
            $products($command->productIds),
            $ids($command->categoryIds),
            $ids($command->brandIds),
            $products($command->excludedProductIds),
            $ids($command->excludedCategoryIds),
            $command->excludeSaleItems,
            $command->combines,
            $command->usageLimit,
            $command->perCustomerLimit,
            $command->firstOrderOnly,
            $command->buyQuantity,
            $command->getQuantity,
            $products($command->getProductIds),
            $ids($command->getCategoryIds),
            $command->giftProductId === null ? null : $this->translations->original($command->giftProductId),
            $command->overridesPriceRules,
            $command->freeShippingEveryRate,
            $command->addsItems,
        );
    }
}
