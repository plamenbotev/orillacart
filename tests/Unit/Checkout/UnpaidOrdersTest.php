<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Application\UnpaidOrders;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class UnpaidOrdersTest extends TestCase
{
    private TestShop $shop;
    private UnpaidOrders $unpaid;
    private int $room;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->room = $room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->stock->setLevels(StockItemRef::product(20), new SetStockLevels([new SetStockLevel($room, 5)]));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->unpaid = new UnpaidOrders($this->shop->orders, $this->shop->paymentOptions, $this->shop->settingsService, $this->shop->clock);
    }

    public function testAnOrderUnpaidTheShopsDaysIsCancelledAndItsStockSellsAgain(): void
    {
        $this->shop->settingsService->update(new UpdateSettings('EUR', null, 'kg', 'cm', null, null, unpaidHoldDays: 7));
        $late = $this->place();
        $paid = $this->place();
        $this->shop->orders->markPaid($paid->number, null);
        self::assertSame(3, $this->shop->stock->available([StockItemRef::product(20)]));

        $this->shop->clock->modify('+6 days');
        self::assertSame(0, $this->unpaid->expire());
        $this->shop->clock->modify('+1 day');
        self::assertSame(1, $this->unpaid->expire());

        self::assertSame('cancelled', $this->shop->orders->order($late->number)->status);
        self::assertSame('processing', $this->shop->orders->order($paid->number)->status);
        self::assertSame(4, $this->shop->stock->available([StockItemRef::product(20)]));
        self::assertContains("ada@example.com: [Lamp Shop] Your order #{$late->number} was cancelled", $this->shop->mail->summary());
    }

    public function testUnpaidOrdersAreCancelledAfterAWeekByDefault(): void
    {
        $order = $this->place();
        $this->shop->clock->modify('+6 days');
        self::assertSame(0, $this->unpaid->expire());

        $this->shop->clock->modify('+2 days');

        self::assertSame(1, $this->unpaid->expire());
        self::assertSame('cancelled', $this->shop->orders->order($order->number)->status);
    }

    public function testNothingIsCancelledWhileTheShopSetsNoDays(): void
    {
        $this->shop->settingsService->update(new UpdateSettings('EUR', null, 'kg', 'cm', null, null, unpaidHoldDays: null));
        $order = $this->place();
        $this->shop->clock->modify('+400 days');

        self::assertSame(0, $this->unpaid->expire());
        self::assertSame('on_hold', $this->shop->orders->order($order->number)->status);
    }

    public function testACustomerHoldsAFewOrdersWaitingForATransferAtOnce(): void
    {
        $this->stocked();
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $orders = [$this->place(customer: $ada), $this->place('ada+2@example.com', customer: $ada), $this->place('other@example.com', customer: $ada)];

        try {
            $this->place(customer: $ada);
            self::fail('three wait for the money');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame(['orillacart_too_many_unpaid_orders', 'Too many orders wait for a payment like this one. Choose another way to pay, or pay for the orders that wait first.'], [$e->errorCode, $e->getMessage()]);
        }
        self::assertSame('on_hold', $this->place('grace@example.com', customer: $this->shop->accounts->add('grace@example.com', 'Grace'))->status, 'another shopper orders');

        $this->shop->orders->markPaid($orders[0]->number, null);
        self::assertSame('on_hold', $this->place(customer: $ada)->status, 'one paid makes room');
    }

    public function testOrdersPlacedInSomeonesEmailNeverLockThemOut(): void
    {
        $this->stocked();
        // Someone else places transfers in Ada's email, and its variants, as a guest.
        foreach (['ada@example.com', 'Ada+a@example.com', 'ADA+b@example.com', 'ada+c@example.com'] as $email) {
            $this->place($email, caller: 'ip:198.51.100.9');
        }

        self::assertSame('on_hold', $this->place(caller: 'ip:203.0.113.7')->status, 'Ada orders from her own address');
        self::assertSame('on_hold', $this->place(customer: $this->shop->accounts->add('ada@example.com', 'Ada'))->status, 'and from her account');
    }

    public function testAGuestHoldsAFewOrdersWaitingForATransferFromOnePlace(): void
    {
        $this->stocked();
        $this->shop->rateLimiter->limits['unpaid_orders'] = 3;
        for ($i = 0; $i < 3; ++$i) {
            $this->place("guest{$i}@example.com", caller: 'ip:198.51.100.9');
        }

        $this->expectException(CheckoutRuleViolation::class);
        $this->expectExceptionMessage('Too many orders wait for a payment like this one.');
        $this->place('guest4@example.com', caller: 'ip:198.51.100.9');
    }

    public function testAnOrderPaidOnlineOrOnDeliveryIsNeverRefusedForOrdersThatWait(): void
    {
        $this->stocked();
        $this->shop->paymentMethods->update('stripe', new SavePaymentMethod('Card', true, 2, settings: ['secret_key' => 'sk_live_shop', 'webhook_secret' => 'whsec_shop']));
        $this->shop->paymentMethods->update('cash_on_delivery', new SavePaymentMethod('Cash on delivery', true, 3));
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        for ($i = 0; $i < 3; ++$i) {
            $this->place(customer: $ada);
        }

        self::assertSame('pending_payment', $this->place(customer: $ada, payment: 'stripe')->status);
        self::assertSame('on_hold', $this->place(customer: $ada, payment: 'cash_on_delivery')->status);
        self::assertSame('on_hold', $this->place(customer: $ada, payment: 'cash_on_delivery')->status, 'orders paid on delivery do not count either');
    }

    private function stocked(): void
    {
        $this->shop->stock->setLevels(StockItemRef::product(20), new SetStockLevels([new SetStockLevel($this->room, 50)]));
    }

    private function place(string $email = 'ada@example.com', ?int $customer = null, string $caller = '', string $payment = 'bank_transfer'): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null, $customer), new AddToCart(20))->newToken, $customer);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder($email, new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: $payment), $caller);
    }
}
