<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Application\UnpaidOrders;
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

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
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

    public function testNothingIsCancelledWhileTheShopSetsNoDays(): void
    {
        $order = $this->place();
        $this->shop->clock->modify('+400 days');

        self::assertSame(0, $this->unpaid->expire());
        self::assertSame('on_hold', $this->shop->orders->order($order->number)->status);
    }

    private function place(): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
    }
}
