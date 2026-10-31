<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application;

use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Coupons\Domain\Basket;
use OrillaCart\Coupons\Domain\BasketLine;
use OrillaCart\Coupons\Domain\CouponRepository;
use OrillaCart\Shared\Domain\Money;

/**
 * How many items a coupon still gives for what is kept of an order (Coupon::earns()), by the coupon as it is now:
 * for what becomes of an item it gave when items it came with go back. Products are judged by their categories
 * and brands as they are now.
 */
final readonly class EarnedItems
{
    public function __construct(
        private CouponRepository $coupons,
        private ProductContent $content,
    ) {}

    /**
     * @param list<array{product_id: int, quantity: int, total: string}> $kept the items kept, but those given:
     *                                                                         what they cost before discounts
     *
     * @return int|null null when the coupon is gone: nothing to judge by
     */
    public function earned(string $code, array $kept, string $currency): ?int
    {
        $coupon = $this->coupons->findByCodes([$code])[$code] ?? null;
        if ($coupon === null) {
            return null;
        }
        $lines = [];
        foreach ($kept as $line) {
            if ($line['quantity'] > 0) {
                $terms = $this->content->termIds($line['product_id']);
                $lines[] = new BasketLine($line['product_id'], $line['quantity'], Money::of($line['total']), false, $terms['categories'], $terms['brands']);
            }
        }

        return $coupon->earns(new Basket($lines, $currency));
    }

    /**
     * Whether the coupon applies to the product, as it is now: false for a coupon gone.
     */
    public function appliesTo(string $code, int $productId): bool
    {
        $coupon = $this->coupons->findByCodes([$code])[$code] ?? null;
        if ($coupon === null) {
            return false;
        }
        $terms = $this->content->termIds($productId);

        return $coupon->appliesTo(new BasketLine($productId, 1, Money::of('1'), false, $terms['categories'], $terms['brands']));
    }
}
