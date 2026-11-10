<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Returns;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Returns\Application\Dto\AnswerReturn;
use OrillaCart\Returns\Application\Dto\RefundReturn;
use OrillaCart\Returns\Application\Dto\RequestReturn;
use OrillaCart\Returns\Application\Dto\ReturnItem;
use OrillaCart\Returns\Application\Dto\ReturnView;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * What a return gives back: a deal's share by price (PLAN 422), a coupon's share taken back once what is kept falls
 * below its minimum and given again with the rest (PLAN 438), and a line's returns adding up to it (PLAN 439).
 */
final class ReturnAmountsTest extends TestCase
{
    private TestShop $shop;
    private int $rate;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG', returnDays: 30));
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('100', weight: '2', stockMode: 'always_available'));
        $this->shop->content->add(20, 'Keychain');
        $this->shop->products->save(20, new SaveProduct('2', weight: '1', stockMode: 'always_available'));
        $this->shop->content->add(30, 'Shade');
        $this->shop->products->save(30, new SaveProduct('60', weight: '1', stockMode: 'always_available'));
        $this->shop->content->add(40, 'Bulb');
        $this->shop->products->save(40, new SaveProduct('40', weight: '1', stockMode: 'always_available'));
        $this->shop->content->add(50, 'Candle');
        $this->shop->products->save(50, new SaveProduct('20', weight: '1', stockMode: 'always_available'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Anywhere', '5')]))->rates[0]->id;
    }

    public function testAFreeKeychainRefundsItsShareByPriceNotHalfTheLamp(): void
    {
        $this->shop->coupons->create(new SaveCoupon('', 'free_gift', '0', 'Keychain with a lamp', productIds: [10], automatic: true, getQuantity: 1, giftProductId: 20));
        $receipt = $this->shipped($this->cart([10, 1]));

        $keychain = $this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(1, 1)], 'no_longer_needed'));

        self::assertSame('1.96', $keychain->amount, '100 paid for 102 of goods: the keychain\'s 2 of it');
    }

    public function testACodesShareComesBackOffTheRefundOnceWhatIsKeptIsBelowItsMinimum(): void
    {
        $this->shop->coupons->create(new SaveCoupon('BIG', 'order_amount', '30', minSubtotal: '100'));
        $owner = $this->cart([30, 1], [40, 1]);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('BIG'));
        $receipt = $this->shipped($owner);

        $shade = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed')));
        self::assertSame('30', $shade->amount, 'the shade paid 42; the bulb kept is 40 without the code, so its 12 off come back');

        $bulb = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(1, 1)], 'no_longer_needed')));
        self::assertSame('40', $bulb->amount, 'the bulb paid 28, and gets the 12 back too');
        self::assertSame('70', $this->shop->orders->order($this->number)->refundedTotal, 'all the items paid for');
    }

    public function testWhatAReturnCannotCoverOfACodeWaitsForTheRest(): void
    {
        $this->shop->coupons->create(new SaveCoupon('BIG', 'order_amount', '30', minSubtotal: '101'));
        $owner = $this->cart([10, 1], [20, 3]);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('BIG'));
        $receipt = $this->shipped($owner);

        $keychain = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(1, 1)], 'no_longer_needed')));
        self::assertSame('1.43', $keychain->amount, 'the lamp and two keychains still come to 104: a third of what the keychains paid');
        $keychains = $this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(1, 2)], 'no_longer_needed'));
        self::assertSame('0', $keychains->amount, 'the lamp kept is 100, below 101: the keychains\' 2.87 go to its 28.30 off');
        $lamp = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed')));
        self::assertSame('70.57', $lamp->amount, 'the lamp paid 71.70; the two keychains kept, not refunded yet, are below the minimum');
        $keychains = $this->refunded($keychains);

        self::assertSame('4', $keychains->amount, 'with nothing kept, what their code took back of the lamp comes back with them');
        self::assertSame('76', $this->shop->orders->order($this->number)->refundedTotal, 'everything back: all that was paid for the items');
    }

    public function testTheReturnsOfALineAddUpToIt(): void
    {
        $owner = $this->cart([50, 3]);
        $this->shop->products->save(50, new SaveProduct('20', weight: '1', stockMode: 'always_available'));
        $this->shop->coupons->create(new SaveCoupon('THIRD', 'order_amount', '40'));
        $this->shop->checkout->applyCoupon($owner, new CouponCode('THIRD'));
        $receipt = $this->shipped($owner);

        $amounts = [];
        for ($i = 0; $i < 3; ++$i) {
            $amounts[] = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed')))->amount;
        }

        self::assertSame(['6.67', '6.67', '6.66'], $amounts, 'three candles paid 20 together');
        self::assertSame('20', $this->shop->orders->order($this->number)->refundedTotal);
    }

    private int $number = 0;

    private function refunded(ReturnView $return): ReturnView
    {
        $this->shop->returns->approve($return->id, new AnswerReturn(), 7);
        $this->shop->returns->receive($return->id, 7);

        return $this->shop->returns->refund($return->id, new RefundReturn(outside: true), 7);
    }

    /**
     * @param array{int, int} ...$lines
     */
    private function cart(array ...$lines): CartOwner
    {
        $owner = new CartOwner(null);
        foreach ($lines as [$product, $quantity]) {
            $owner = new CartOwner($this->shop->carts->add($owner, new AddToCart($product, quantity: $quantity))->newToken ?? $owner->token);
        }

        return $owner;
    }

    private function shipped(CartOwner $owner): string
    {
        $this->number = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$this->rate}", payment: 'bank_transfer'))->number;
        $this->shop->orders->markPaid($this->number, null);
        $this->shop->orders->changeStatus($this->number, new ChangeOrderStatus('shipped'), 7);

        return $this->shop->receipts->issue($this->number);
    }
}
