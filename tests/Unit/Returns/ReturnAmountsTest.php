<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Returns;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\GiftCards\Domain\GiftCardSettings;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Returns\Application\Dto\AnswerReturn;
use OrillaCart\Returns\Application\Dto\RefundReturn;
use OrillaCart\Returns\Application\Dto\RequestReturn;
use OrillaCart\Returns\Application\Dto\ReturnItem;
use OrillaCart\Returns\Application\Dto\ReturnView;
use OrillaCart\Settings\Application\Dto\CurrencySettingsView;
use OrillaCart\Settings\Application\Dto\OfferedCurrencyView;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\FreeItemsOnReturn;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a return gives back: a deal's share by price, a coupon's share taken back once what is kept falls below its
 * minimum and given again with the rest, and a line's returns adding up to what it paid.
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

    public function testACodesShareTakenBackOnTheFirstItemIsGivenBackOnceOnTheLast(): void
    {
        $this->shop->coupons->create(new SaveCoupon('BIG', 'order_amount', '30', minSubtotal: '100'));
        $owner = $this->cart([30, 2]);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('BIG'));
        $receipt = $this->shipped($owner);

        $first = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed')));
        $last = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed')));

        self::assertSame(['30', '60'], [$first->amount, $last->amount], 'the first shade is 45 less the 15 the code took back; the last is 45 and the 15 again');
        self::assertSame('90', $this->shop->orders->order($this->number)->refundedTotal, 'the two shades paid 90');
    }

    public function testThreeBulbsReturnedOneByOneRefundWhatTheyPaid(): void
    {
        $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Anywhere', '20')]));
        $this->shop->coupons->create(new SaveCoupon('BIG', 'order_amount', '30', minSubtotal: '100'));
        $owner = $this->cart([40, 3]);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('BIG'));
        $receipt = $this->shipped($owner);

        $amounts = [];
        for ($i = 0; $i < 3; ++$i) {
            $amounts[] = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed')))->amount;
        }

        self::assertSame(['10', '40', '40'], $amounts);
        self::assertSame('90', $this->shop->orders->order($this->number)->refundedTotal);
    }

    public function testADealOverSeveralLinesRefundsEachLinesShareOnce(): void
    {
        $this->shop->coupons->create(new SaveCoupon('', 'free_gift', '0', 'Shades with lamps', productIds: [10], automatic: true, getQuantity: 2, giftProductId: 30));
        $receipt = $this->shipped($this->cart([10, 2]));

        $first = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1), new ReturnItem(1, 1)], 'no_longer_needed')));
        $shade = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(1, 1)], 'no_longer_needed')));
        $lamp = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed')));

        self::assertSame(['100', '37.5', '62.5'], [$first->amount, $shade->amount, $lamp->amount], 'the lamps\' 200 shared by price: 125 for the lamps, 75 for the shades');
        self::assertSame('200', $this->shop->orders->order($this->number)->refundedTotal);
    }

    public function testEverythingReturnedInStepsRefundsAllThatWasPaid(): void
    {
        $this->shop->coupons->create(new SaveCoupon('', 'free_gift', '0', 'Shade with lamps', productIds: [10], automatic: true, getQuantity: 1, giftProductId: 30));
        $receipt = $this->shipped($this->cart([10, 2]));

        $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1), new ReturnItem(1, 1)], 'no_longer_needed')));
        $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed')));

        self::assertSame('200', $this->shop->orders->order($this->number)->refundedTotal);
    }

    public function testTwoDealsThatShareALampAreSharedOutTogether(): void
    {
        $this->shop->coupons->create(new SaveCoupon('', 'free_gift', '0', 'Keychain with a lamp', productIds: [10], combines: true, automatic: true, getQuantity: 1, giftProductId: 20));
        $this->shop->coupons->create(new SaveCoupon('', 'free_gift', '0', 'Candle with a lamp', productIds: [10], combines: true, automatic: true, getQuantity: 1, giftProductId: 50));
        $receipt = $this->shipped($this->cart([10, 1]));

        $amounts = [];
        foreach ([2, 1, 0] as $line) {
            $amounts[] = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem($line, 1)], 'no_longer_needed')))->amount;
        }

        self::assertSame(['16.39', '1.64', '81.97'], $amounts, 'the lamp\'s 100 shared over 122 of goods');
        self::assertSame('100', $this->shop->orders->order($this->number)->refundedTotal);
    }

    public function testADealSharesWhatItsItemsPaidAfterOtherCodes(): void
    {
        $this->shop->coupons->create(new SaveCoupon('', 'free_gift', '0', 'Keychain with a lamp or shade', productIds: [10, 30], combines: true, automatic: true, getQuantity: 1, giftProductId: 20));
        $this->shop->coupons->create(new SaveCoupon('HALF', 'percent', '50', productIds: [30], combines: true));
        $owner = $this->cart([10, 1], [30, 1]);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('HALF'));
        $receipt = $this->shipped($owner);
        $shade = array_key_first(array_filter($this->shop->orders->order($this->number)->lines, static fn($line): bool => $line->productId === 30));
        self::assertNotNull($shade);

        $returned = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem($shade, 1)], 'no_longer_needed')));

        self::assertSame('29.55', $returned->amount, 'the shade paid 30, less its part of the keychain');
    }

    public function testAGiftCardKeptDoesNotHoldACodesMinimum(): void
    {
        $this->shop->content->add(60, 'Gift card');
        $this->shop->products->save(60, new SaveProduct('100', 'virtual', taxable: false));
        $this->shop->giftCardSettings->save(new GiftCardSettings([60]));
        $this->shop->coupons->create(new SaveCoupon('BIG', 'order_amount', '30', minSubtotal: '100'));
        $owner = $this->cart([30, 1], [40, 1], [60, 1]);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('BIG'));
        $receipt = $this->shipped($owner);

        $shade = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed')));

        self::assertSame('30', $shade->amount, 'the bulb kept is 40 of a 100 minimum: the code\'s share of what is kept comes back');
    }

    public function testACodesMinimumIsJudgedInTheShopsCurrency(): void
    {
        $this->shop->currencies->save(new CurrencySettingsView([new OfferedCurrencyView('PLN', '4')]));
        $this->shop->chosenCurrency->currency = 'PLN';
        $this->shop->coupons->create(new SaveCoupon('BIG', 'order_amount', '30', minSubtotal: '100'));
        $owner = $this->cart([30, 1], [40, 1]);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('BIG'));
        $receipt = $this->shipped($owner);

        $shade = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed')));

        self::assertSame('120', $shade->amount, 'the bulb kept is 160 PLN, 40 EUR: below the 100 EUR minimum, as on a EUR order');
    }

    /** @return iterable<string, array{FreeItemsOnReturn}> */
    public static function policiesThatDoNotShare(): iterable
    {
        yield 'charged' => [FreeItemsOnReturn::Charged];
        yield 'they come back' => [FreeItemsOnReturn::ComeBack];
        yield 'they stay free' => [FreeItemsOnReturn::StayFree];
    }

    #[DataProvider('policiesThatDoNotShare')]
    public function testAFreeItemSentBackAloneClosesWithNoMoney(FreeItemsOnReturn $policy): void
    {
        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG', returnDays: 30, freeItemsOnReturn: $policy));
        $this->shop->coupons->create(new SaveCoupon('', 'free_gift', '0', 'Shades with lamps', productIds: [10], automatic: true, getQuantity: 2, giftProductId: 30));
        $receipt = $this->shipped($this->cart([10, 2]));
        $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1), new ReturnItem(1, 1)], 'no_longer_needed')));

        $shade = $this->refunded($this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(1, 1)], 'no_longer_needed')));

        self::assertSame(['refunded', '0'], [$shade->status, $shade->amount]);
        $order = $this->shop->orders->order($this->number);
        self::assertSame([2, '100'], [$order->lines[1]->refundedQuantity, $order->refundedTotal], 'both shades are back; no money for the free one');
        self::assertCount(1, $this->shop->refunds->forOrder($this->number)->refunds, 'no refund of nothing');
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
