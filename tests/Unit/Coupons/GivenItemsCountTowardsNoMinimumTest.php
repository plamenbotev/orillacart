<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Coupons;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An item a coupon gives costs the customer nothing, so it lifts no other coupon's minimum, of money or of items.
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

    /** @return iterable<string, array{bool, bool}> */
    public static function couponOrders(): iterable
    {
        yield 'the gift first, combining' => [true, true];
        yield 'the percentage first, combining' => [false, true];
        yield 'the gift first, alone' => [true, false];
        yield 'the percentage first, alone' => [false, false];
    }

    #[DataProvider('couponOrders')]
    public function testAFreeItemDoesNotMakeUpAnotherCouponsLeastItems(bool $giftFirst, bool $combines): void
    {
        $shop = new TestShop();
        $shop->content->add(10, 'Lamp');
        $shop->products->save(10, new SaveProduct('100', weight: '1'));
        $shop->content->add(20, 'Keychain');
        $shop->products->save(20, new SaveProduct('2', weight: '1'));
        $gift = new SaveCoupon('', 'free_gift', '0', 'Keychain with a lamp', productIds: [10], combines: $combines, automatic: true, getQuantity: 1, giftProductId: 20);
        $percent = new SaveCoupon('', 'percent', '20', 'Two or more: 20% off', minQuantity: 2, combines: $combines, automatic: true);
        foreach ($giftFirst ? [$gift, $percent] : [$percent, $gift] as $coupon) {
            $shop->coupons->create($coupon);
        }
        $this->shop = $shop;

        $owner = $this->cart(10);
        $summary = $shop->checkout->summary($owner, new CheckoutDetails('BG'));

        $lines = array_map(static fn($line): array => [$line->productId, $line->quantity], $shop->carts->cart($owner)->cart->lines);
        self::assertSame([[10, 1], [20, 1]], $lines, 'the keychain is given');
        self::assertSame(['Keychain with a lamp'], array_map(static fn($coupon): string => $coupon->description, $summary->coupons), 'one lamp is not two items: no 20% off');
        self::assertSame(['102', '2'], [$summary->subtotal, $summary->discount], 'the lamp at its price, the keychain free');
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
