<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Reports;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderContact;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Domain\Event\OrderChanged;
use OrillaCart\Ordering\Domain\Event\OrderDeleted;
use OrillaCart\Ordering\Domain\Event\OrderPlaced;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Reports\Application\ProjectOrders;
use OrillaCart\Reports\Application\RebuildReports;
use OrillaCart\Reports\Application\ShopCalendar;
use OrillaCart\Reports\Infrastructure\Events\CountChangedOrders;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Reports\InMemoryReportStore;
use PHPUnit\Framework\TestCase;

/**
 * Orders are counted again whenever they change, in the shop's time zone, with refunds on the day they were made.
 */
final class ReportProjectionTest extends TestCase
{
    private TestShop $shop;
    private InMemoryReportStore $store;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->store = new InMemoryReportStore();
        $calendar = new class implements ShopCalendar {
            public function timezone(): \DateTimeZone
            {
                return new \DateTimeZone('America/New_York');
            }
        };
        $this->shop->jobs->with(new ProjectOrders($this->shop->orders, $this->shop->refunds, $this->store, $calendar), new RebuildReports($this->shop->orders, $this->shop->jobs->jobs));
        $counting = new CountChangedOrders($this->shop->jobs->jobs);
        $this->shop->events->addListener(OrderPlaced::class, $counting->placed(...));
        $this->shop->events->addListener(OrderStatusChanged::class, $counting->statusChanged(...));
        $this->shop->events->addListener(OrderRefunded::class, $counting->refunded(...));
        $this->shop->events->addListener(OrderChanged::class, $counting->changed(...));
        $this->shop->events->addListener(OrderDeleted::class, $counting->deleted(...));
    }

    public function testAnOrderCountsOnceItIsPaidOnTheShopsDayAndRefundsOnTheirs(): void
    {
        $this->shop->clock->modify('2030-01-01 03:00:00');
        $order = $this->place(2);
        $facts = $this->store->facts[$order->number];
        self::assertSame([false, '2029-12-31', '16', 2], [$facts->order->counted, $facts->order->day->format('Y-m-d'), $facts->order->gross, $facts->order->items], 'three in the morning in UTC is the evening before in New York; on hold is not a sale yet');

        $this->shop->orders->markPaidByShop($order->number, '', 7);
        self::assertTrue($this->store->facts[$order->number]->order->counted);
        self::assertSame([[20, 2, '16']], array_map(static fn($line): array => [$line->productId, $line->quantity, $line->net], $this->store->facts[$order->number]->lines));

        $this->shop->clock->modify('+3 days');
        $this->shop->refunds->refund($order->number, new MakeRefund('8', [new RefundLine(0, 1)]), 7);
        $refunds = $this->store->facts[$order->number]->refunds;
        self::assertSame([['8', '2030-01-03']], array_map(static fn($refund): array => [$refund->amount, $refund->day->format('Y-m-d')], $refunds), 'on the day the money went back');
        self::assertSame('2029-12-31', $this->store->facts[$order->number]->order->day->format('Y-m-d'), 'the order keeps its day');

        $this->shop->orders->changeContact($order->number, new ChangeOrderContact('ada@example.com', new AddressData('Ada', 'King', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000')), 7);
        self::assertSame('Ada King', $this->store->facts[$order->number]->order->customerName, 'an edited order is counted again');
    }

    public function testADeletedOrderIsForgottenAndARebuildCountsEveryOrder(): void
    {
        $kept = $this->place(1);
        $deleted = $this->place(1);
        $this->shop->orders->delete($deleted->number);
        self::assertSame([$kept->number], array_keys($this->store->facts));

        $this->store->facts = [];
        $this->shop->jobs->jobs->push(RebuildReports::TYPE, ['after' => 0]);
        self::assertSame([$kept->number], array_keys($this->store->facts));
    }

    private function place(int $quantity): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20, quantity: $quantity))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
    }
}
