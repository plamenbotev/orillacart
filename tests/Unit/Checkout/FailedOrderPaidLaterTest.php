<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PayOrder;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * A failed order is paid later (Pay now) only within the payment window of its last try, and only as the shop
 * would still sell it: its prices as they are now, each of its coupons still holding (PLAN 421).
 */
final class FailedOrderPaidLaterTest extends TestCase
{
    private TestShop $shop;
    private int $rate;
    private int $carrier;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('100', weight: '1'));
        $carrier = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Bulgaria', '5', regions: ['BG'])]));
        $this->carrier = $carrier->id;
        $this->rate = $carrier->rates[0]->id;
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testAFirstOrderCodeIsNotHadTwiceThroughFailedOrders(): void
    {
        $this->shop->coupons->create(new SaveCoupon('WELCOME', 'percent', '50', '', firstOrderOnly: true));
        $numbers = [];
        for ($i = 0; $i < 3; ++$i) {
            $owner = $this->cart();
            $this->shop->checkout->applyCoupon($owner, new CouponCode('WELCOME'));
            $numbers[] = $number = $this->shop->checkout->placeOrder($owner, $this->order())->number;
            $this->shop->orders->fail($number, 'Declined.');
        }

        $this->shop->payNow->pay($this->shop->receipts->issue($numbers[0]), new PayOrder('bank_transfer'));
        foreach ([$numbers[1], $numbers[2]] as $number) {
            try {
                $this->shop->payNow->pay($this->shop->receipts->issue($number), new PayOrder('bank_transfer'));
                self::fail('the first order is another');
            } catch (CheckoutRuleViolation $e) {
                self::assertSame(['orillacart_coupon_refused', 'The code WELCOME no longer holds for this order: This code is for a first order only. Order again from the shop.'], [$e->errorCode, $e->getMessage()]);
            }
            self::assertSame('failed', $this->shop->orders->order($number)->status);
        }
        self::assertSame('on_hold', $this->shop->orders->order($numbers[0])->status);
    }

    public function testAFailedOrderIsNotPayableOnceItsPaymentWindowClosed(): void
    {
        $number = $this->failed();
        self::assertTrue($this->shop->orders->order($number)->payable);

        $this->shop->clock->modify('+3 hours');

        $order = $this->shop->orders->order($number);
        self::assertFalse($order->payable);
        self::assertSame([], $this->shop->payNow->methods($order));
        $this->expectException(CheckoutRuleViolation::class);
        $this->shop->payNow->pay($this->shop->receipts->issue($number), new PayOrder('bank_transfer'));
    }

    public function testAnExpiredCodeIsCheckedAgain(): void
    {
        $this->shop->coupons->create(new SaveCoupon('FLASH', 'percent', '50', '', endsAt: $this->shop->clock->now()->modify('+1 hour')->format(\DATE_ATOM)));
        $number = $this->failed('FLASH');
        $this->shop->clock->modify('+2 hours');

        try {
            $this->shop->payNow->pay($this->shop->receipts->issue($number), new PayOrder('bank_transfer'));
            self::fail('expired');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('The code FLASH no longer holds for this order: This code has expired. Order again from the shop.', $e->getMessage());
        }
        self::assertSame('failed', $this->shop->orders->order($number)->status);
    }

    public function testAPriceChangedSinceIsNotPaidAtTheOldOne(): void
    {
        $number = $this->failed();
        $this->shop->products->save(10, new SaveProduct('300', weight: '1'));

        try {
            $this->shop->payNow->pay($this->shop->receipts->issue($number), new PayOrder('bank_transfer'));
            self::fail('priced again');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_order_not_payable', $e->errorCode);
        }
        self::assertSame('failed', $this->shop->orders->order($number)->status);
    }

    public function testShippingThatCostsMoreNowIsNotPaidAtTheOldPrice(): void
    {
        $number = $this->failed();
        $this->shop->carriers->update($this->carrier, new SaveCarrier('Courier', rates: [new SaveShippingRate('Bulgaria', '9', $this->rate, regions: ['BG'])]));
        self::assertSame("rate:{$this->rate}", $this->shop->orders->order($number)->shippingOption, 'the same rate, at another price');

        try {
            $this->shop->payNow->pay($this->shop->receipts->issue($number), new PayOrder('bank_transfer'));
            self::fail('shipping priced again');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_order_not_payable', $e->errorCode);
        }
        self::assertSame('failed', $this->shop->orders->order($number)->status);
    }

    public function testAFailedOrderIsPaidOnlyWithinTheWindowOfItsFirstWait(): void
    {
        $owner = $this->cart();
        $this->shop->paymentMethods->update('stripe', new SavePaymentMethod('Card', true, settings: ['secret_key' => 'sk_live_shop', 'webhook_secret' => 'whsec_shop']));
        $number = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$this->rate}", payment: 'stripe'))->number;
        $this->shop->clock->modify('+2 hours');
        $this->shop->orders->fail($number, 'Declined.');
        $this->shop->payNow->pay($this->shop->receipts->issue($number), new PayOrder('stripe'));
        $this->shop->orders->fail($number, 'Declined again.');

        $this->shop->clock->modify('+61 minutes');

        self::assertFalse($this->shop->orders->order($number)->payable, 'three hours from when it was placed, not from the last try');
    }

    public function testAFailedOrderAsItWasIsPaidAgain(): void
    {
        $this->shop->coupons->create(new SaveCoupon('SPRING', 'percent', '10', ''));
        $number = $this->failed('SPRING');
        $this->shop->clock->modify('+2 hours');

        $paid = $this->shop->payNow->pay($this->shop->receipts->issue($number), new PayOrder('bank_transfer'));

        self::assertSame(['on_hold', '95'], [$paid->status, $paid->total]);
        self::assertSame(1, $this->shop->coupons->coupon(1)->uses);
    }

    private function failed(?string $code = null): int
    {
        $owner = $this->cart();
        if ($code !== null) {
            $this->shop->checkout->applyCoupon($owner, new CouponCode($code));
        }
        $number = $this->shop->checkout->placeOrder($owner, $this->order())->number;
        $this->shop->orders->fail($number, 'Declined.');

        return $number;
    }

    private function cart(): CartOwner
    {
        $owner = new CartOwner(null);

        return new CartOwner($this->shop->carts->add($owner, new AddToCart(10))->newToken ?? $owner->token);
    }

    private function order(): PlaceOrder
    {
        return new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$this->rate}", payment: 'bank_transfer');
    }
}
