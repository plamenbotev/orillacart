<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class ReorderTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->content->add(30, 'Album');
        $this->shop->products->save(30, new SaveProduct('12', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testTheOrdersItemsGoInTheCartAgainAtTodaysPricesAndThoseNotForSaleStayOut(): void
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20, quantity: 2))->newToken);
        $owner = new CartOwner($this->shop->carts->add($owner, new AddToCart(30))->newToken ?? $owner->token);
        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
        $this->shop->products->save(20, new SaveProduct('9', 'virtual'));
        $this->shop->products->save(30, new SaveProduct('12', 'virtual', purchasable: false));

        $view = $this->shop->reorder->fromReceipt(new CartOwner(null), $this->shop->receipts->issue($placed->number));

        self::assertSame(['Album'], $view->missed);
        self::assertNotNull($view->result->newToken, 'a new cart for a browser without one');
        self::assertSame([['E-book', 2, '9']], array_map(static fn($line): array => [$line->name, $line->quantity, $line->unitPrice], $view->result->cart->lines));
    }

    public function testOnlyALinkTheShopMadeOrdersAgain(): void
    {
        $this->expectException(OrderNotFound::class);
        $this->shop->reorder->fromReceipt(new CartOwner(null), '1-1-' . str_repeat('0', 32));
    }
}
