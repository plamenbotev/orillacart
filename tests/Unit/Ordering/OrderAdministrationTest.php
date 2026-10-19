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
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Domain\Event\PaymentCancelled;
use OrillaCart\Payment\Domain\Event\PaymentCompleted;
use OrillaCart\Payment\Domain\Event\PaymentFailed;
use OrillaCart\Payment\Domain\Payment;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shipping\Domain\Consignment;
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

        $view = $this->shop->orders->markPaidByShop($order->number, 'Money arrived.', 7);
        self::assertSame(['processing', ['shipped', 'completed']], [$view->status, $view->nextStatuses], 'a paid order is refunded, not cancelled');
        try {
            $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('cancelled'), 7);
            self::fail('cancelling a paid order would keep the money');
        } catch (OrderRuleViolation $e) {
            self::assertSame('orillacart_invalid_status_change', $e->errorCode);
        }
        $view = $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('shipped'), 7);
        self::assertSame(['completed'], $view->nextStatuses, 'refunding is a refund, not a status');

        $notes = $this->shop->orders->order($order->number)->notes;
        self::assertSame(['system', 'system', 'shop', 'shop'], array_column($notes, 'author'));
        self::assertSame('Status changed from on hold to processing. Money arrived.', $notes[2]->text);
        self::assertNotNull($this->shop->orderRecords->orders[$order->number]->paidAt());
    }

    public function testAnOrderPaidOnDeliveryShipsUnpaidAndIsPaidWhenCompleted(): void
    {
        $order = $this->place();

        $view = $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('processing'), 7);
        self::assertSame(['processing', null], [$view->status, $view->paidAt], 'fulfilled before the money comes');
        $view = $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('shipped'), 7);
        self::assertSame(['completed', 'cancelled'], $view->nextStatuses, 'the customer may refuse it, unpaid');
        try {
            $this->shop->refunds->refund($order->number, new MakeRefund('8'), 7);
            self::fail('nothing was paid');
        } catch (OrderRuleViolation $e) {
            self::assertSame('orillacart_order_not_refundable', $e->errorCode);
        }

        $view = $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('completed'), 7);
        self::assertSame('completed', $view->status);
        self::assertNotNull($view->paidAt, 'the courier handed over the cash');
    }

    public function testAnUnpaidOrderRefusedAtTheDoorIsCancelledWithItsStockBack(): void
    {
        $order = $this->place(2);
        $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('processing'), 7);
        $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('shipped'), 7);
        self::assertSame(8, $this->available());

        $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('cancelled', note: 'Refused at the door.'), 7);

        self::assertSame(10, $this->available());
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
            $this->shop->orders->markPaidByShop($order->number, '', 7);
        }
        $this->shop->refunds->refund($refunded->number, new MakeRefund('16', [new RefundLine(0, 2)], restock: true), 7);
        $this->shop->refunds->refund($kept->number, new MakeRefund('8', [new RefundLine(0, 1)]), 7);
        self::assertSame('refunded', $this->shop->orders->order($kept->number)->status);
        self::assertSame(9, $this->available(), 'the refund without restock keeps its item out of stock');
    }

    public function testOnlyACancelledOrFailedOrderIsDeletedAndAPaidOneStaysForTheBooks(): void
    {
        $waiting = $this->place(3);
        $paid = $this->place(2);
        self::assertSame(5, $this->available());

        try {
            $this->shop->orders->delete($waiting->number);
            self::fail('an open order');
        } catch (OrderRuleViolation $e) {
            self::assertSame('orillacart_order_open', $e->errorCode);
        }
        $this->shop->orders->cancel($waiting->number);
        $this->shop->orders->delete($waiting->number);
        self::assertSame(8, $this->available(), 'cancelling gave the stock back; deleting leaves it');

        $this->shop->orders->markPaidByShop($paid->number, '', 7);
        try {
            $this->shop->orders->delete($paid->number);
            self::fail('a paid order');
        } catch (OrderRuleViolation $e) {
            self::assertSame('orillacart_order_paid', $e->errorCode);
        }

        $this->shop->refunds->refund($paid->number, new MakeRefund('16'), 7);
        try {
            $this->shop->orders->delete($paid->number);
            self::fail('refunded, but it has an invoice');
        } catch (OrderRuleViolation $e) {
            self::assertSame('orillacart_order_paid', $e->errorCode);
        }
        self::assertCount(1, $this->shop->orderRecords->orders);
    }

    public function testAProvidersLateWordAboutADeletedOrderIsLogged(): void
    {
        foreach ([new PaymentFailed(99, 5, 'paypal'), new PaymentCancelled(99, 5, 'paypal'), new PaymentCompleted(99, 5, 'paypal', 'TX-1')] as $event) {
            $this->shop->events->dispatch($event);
        }

        self::assertCount(3, array_filter($this->shop->logger->records, static fn(array $record): bool => $record['level'] === 'error' && str_contains($record['message'], 'no longer exists')));
    }

    public function testAnOrderAPaymentOrAShipmentStillHoldsStays(): void
    {
        $order = $this->place();
        $this->shop->orders->cancel($order->number);
        $payment = new Payment($order->number, 'paypal', Money::of('8'), 'EUR', $this->shop->clock->now());
        $payment->refer('PAYPAL-1');
        $this->shop->paymentRecords->save($payment);
        $consignment = new Consignment($order->number, 1, 'Speedy', 'standard', 'SP1', null, $this->shop->clock->now());
        $this->shop->consignmentRecords->save($consignment);

        foreach ([['orillacart_order_shipped', static fn() => $consignment->cancel(new \DateTimeImmutable())], ['orillacart_order_payment_open', static fn() => $payment->cancel(new \DateTimeImmutable())]] as [$code, $let]) {
            try {
                $this->shop->orders->delete($order->number);
                self::fail($code);
            } catch (RuleViolation $e) {
                self::assertSame($code, $e->errorCode);
            }
            $let();
        }
        $this->shop->orders->delete($order->number);

        self::assertSame([], $this->shop->orderRecords->orders);
        self::assertSame([], $this->shop->paymentRecords->payments, 'the given-up payment and the bank transfer that never came go along');
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

    public function testTheShopNarrowsTheListByDayPaymentTotalAndWhoOrderedAndSortsByTotal(): void
    {
        $this->shop->paymentMethods->update('cheque', new SavePaymentMethod('Cheque', true));
        $this->shop->clock->modify('2030-03-01 10:00:00');
        $small = $this->place(1);
        $this->shop->clock->modify('2030-03-02 10:00:00');
        $large = $this->place(3);
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20, quantity: 2))->newToken);
        $byCheque = $this->shop->checkout->placeOrder($owner, new PlaceOrder('bo@example.com', new AddressData('Bo', 'Peep', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'cheque'));

        $numbers = fn(OrderQuery $query): array => array_map(static fn($order): int => $order->number, $this->shop->orders->orders($query, true)->orders);
        self::assertSame([$byCheque->number, $large->number], $numbers(new OrderQuery(placedFrom: '2030-03-02T00:00:00+00:00')));
        self::assertSame([$small->number], $numbers(new OrderQuery(placedBefore: '2030-03-02T01:00:00+02:00')), 'the shop’s day, in UTC');
        self::assertSame([$byCheque->number], $numbers(new OrderQuery(payment: 'cheque')));
        self::assertSame([$byCheque->number, $small->number], $numbers(new OrderQuery(maxTotal: '16')));
        self::assertSame([$large->number], $numbers(new OrderQuery(minTotal: '16.01')));
        self::assertSame([], $numbers(new OrderQuery(placedBy: 'account')), 'only guests ordered');
        self::assertSame([$small->number, $byCheque->number, $large->number], $numbers(new OrderQuery(orderBy: 'total', order: 'asc')));
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
