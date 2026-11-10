<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Coupons;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * An item a coupon gives costs the customer nothing, so it lifts no coupon's minimum (PLAN 424).
 */
final class GivenItemsCountTowardsNoMinimumTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('40', weight: '1'));
        $this->shop->content->add(30, 'Shade');
        $this->shop->products->save(30, new SaveProduct('60', weight: '1'));
        $this->shop->coupons->create(new SaveCoupon('', 'free_gift', '0', 'Shade with a lamp', productIds: [10], combines: true, automatic: true, getQuantity: 1, giftProductId: 30));
        $this->shop->coupons->create(new SaveCoupon('BIG', 'order_amount', '30', minSubtotal: '100', combines: true));
    }

    public function testAFreeShadeDoesNotLiftALampPastAnotherCodesMinimum(): void
    {
        $owner = $this->cart(10);
        self::assertCount(2, $this->shop->carts->cart($owner)->cart->lines, 'the shade is given');

        try {
            $this->shop->checkout->applyCoupon($owner, new CouponCode('BIG'));
            self::fail('the lamp is 40');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('This code needs an order of at least 100 EUR.', $e->getMessage());
        }
    }

    public function testAShadeBoughtStillCounts(): void
    {
        $owner = $this->cart(10, 30);

        $summary = $this->shop->checkout->applyCoupon($owner, new CouponCode('BIG'));

        self::assertSame(['BIG' => '30'], array_column(array_map(static fn($coupon): array => [$coupon->code, $coupon->amount], array_filter($summary->coupons, static fn($coupon): bool => $coupon->code === 'BIG')), 1, 0), 'the lamp and the shade bought come to 100');
    }

    private function cart(int ...$products): CartOwner
    {
        $owner = new CartOwner(null);
        foreach ($products as $product) {
            $owner = new CartOwner($this->shop->carts->add($owner, new AddToCart($product))->newToken ?? $owner->token);
        }

        return $owner;
    }
}
