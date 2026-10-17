<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Ordering;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderContact;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class OrderAdministrationTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->shop->stock->setLevels(StockItemRef::product(20), new SetStockLevels([new SetStockLevel($room, 10)]));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testAnOrderMovesAlongItsStatusesAndRemembersWhoMovedIt(): void
    {
        $order = $this->place();

        $view = $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('processing', note: 'Money arrived.'), 7);
        self::assertSame(['shipped', 'completed', 'cancelled', 'refunded'], $view->nextStatuses);
        $view = $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('shipped'), 7);
        self::assertSame(['completed', 'refunded'], $view->nextStatuses);

        $notes = $this->shop->orders->order($order->number)->notes;
        self::assertSame(['system', 'system', 'shop', 'shop'], array_column($notes, 'author'));
        self::assertSame('Status changed from on hold to processing. Money arrived.', $notes[2]->text);
        self::assertNotNull($this->shop->orderRecords->orders[$order->number]->paidAt());
    }

    public function testOnlyTheAllowedMovesAreAccepted(): void
    {
        $order = $this->place();

        foreach (['shipped', 'completed', 'refunded', 'failed', 'pending_payment'] as $status) {
            try {
                $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus($status), 7);
                self::fail("on hold to {$status}");
            } catch (OrderRuleViolation $e) {
                self::assertSame('orillacart_invalid_status_change', $e->errorCode);
            }
        }

        $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('cancelled'), 7);
        self::assertSame([], $this->shop->orders->order($order->number)->nextStatuses, 'a cancelled order is final');
    }

    public function testCancellingPutsTheStockBackAndARefundOnlyWhenAsked(): void
    {
        $cancelled = $this->place(3);
        $refunded = $this->place(2);
        $kept = $this->place(1);
        self::assertSame(4, $this->available());

        $this->shop->orders->changeStatus($cancelled->number, new ChangeOrderStatus('cancelled'), 7);
        self::assertSame(7, $this->available());

        foreach ([$refunded, $kept] as $order) {
            $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('processing'), 7);
        }
        $this->shop->orders->changeStatus($refunded->number, new ChangeOrderStatus('refunded', restock: true), 7);
        $this->shop->orders->changeStatus($kept->number, new ChangeOrderStatus('refunded'), 7);
        self::assertSame(9, $this->available(), 'the refund without restock keeps its item out of stock');
    }

    public function testOnlyClosedOrdersCanBeDeleted(): void
    {
        $order = $this->place();
        try {
            $this->shop->orders->delete($order->number);
            self::fail('an order on hold');
        } catch (OrderRuleViolation $e) {
            self::assertSame('orillacart_order_not_closed', $e->errorCode);
        }

        $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('cancelled'), 7);
        $this->shop->orders->delete($order->number);
        self::assertSame([], $this->shop->orderRecords->orders);
    }

    public function testTheShopFindsOrdersByStatusNumberOrCustomer(): void
    {
        $first = $this->place(1, 'ada@example.com');
        $second = $this->place(1, 'grace@example.com');
        $this->shop->orders->changeStatus($second->number, new ChangeOrderStatus('processing'), 7);

        self::assertSame([$second->number, $first->number], array_column($this->shop->orders->orders(new OrderQuery())->orders, 'number'));
        self::assertSame([$second->number], array_column($this->shop->orders->orders(new OrderQuery('processing'))->orders, 'number'));
        self::assertSame([$first->number], array_column($this->shop->orders->orders(new OrderQuery(search: 'ADA@'))->orders, 'number'));
        self::assertSame([$first->number], array_column($this->shop->orders->orders(new OrderQuery(search: "#{$first->number}"))->orders, 'number'));

        $page = $this->shop->orders->orders(new OrderQuery(page: 2, perPage: 1));
        self::assertSame([[$first->number], 2, 2], [array_column($page->orders, 'number'), $page->total, $page->pages]);
        self::assertSame(['Ada Lovelace', 1, '8', 'Bank transfer'], [$page->orders[0]->customer, $page->orders[0]->items, $page->orders[0]->total, $page->orders[0]->paymentName]);
    }

    public function testTheShopCorrectsTheCustomersDetails(): void
    {
        $order = $this->place();

        $view = $this->shop->orders->changeContact($order->number, new ChangeOrderContact('ada.l@example.com', new AddressData('Ada', 'King', '2 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 111 1111')), 7);

        self::assertSame(['ada.l@example.com', 'King'], [$view->email, $view->billing->lastName]);
        $this->expectException(OrderRuleViolation::class);
        $this->shop->orders->changeContact($order->number, new ChangeOrderContact('ada@example.com', $view->billing, $view->billing), 7);
    }

    private function available(): ?int
    {
        return $this->shop->stock->stock(StockItemRef::product(20))->available;
    }

    private function place(int $quantity = 1, string $email = 'ada@example.com'): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20, quantity: $quantity))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder($email, new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
    }
}
