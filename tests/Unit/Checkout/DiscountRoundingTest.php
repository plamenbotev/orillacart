<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * Discounts are rounded to the currency's cents one after another: together they never take more than an item costs.
 */
final class DiscountRoundingTest extends TestCase
{
    public function testTwoDiscountsThatTakeAWholeItemOffTakeNoMoreThanItCosts(): void
    {
        $shop = new TestShop();
        $shop->content->add(20, 'E-book');
        $shop->products->save(20, new SaveProduct('9.95', 'virtual'));
        $shop->coupons->create(new SaveCoupon('', 'percent', '10', '10 % off', combines: true, automatic: true));
        $shop->coupons->create(new SaveCoupon('FREE', 'percent', '100', 'On the house', combines: true));
        $owner = new CartOwner($shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
        $shop->checkout->applyCoupon($owner, new CouponCode('FREE'));

        // 10 % of 9.95 is 0.995, rounded 1.00; 100 % of the 8.955 left would round to 8.96, a cent more than is left.
        $summary = $shop->checkout->summary($owner, new CheckoutDetails('BG', billingCountry: 'BG'));
        self::assertSame(['9.95', '0'], [$summary->discount, $summary->total]);

        $placed = $shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000')));
        self::assertSame('0', $shop->orders->order($placed->number)->total);
    }
}
