<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Reports;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
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
use OrillaCart\Shared\Security\Signatures;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Jobs\RecordedProgress;
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
        $this->shop->jobs->with(new ProjectOrders($this->shop->orders, $this->shop->refunds, $this->store, $calendar, new Signatures('test secret')), new RebuildReports($this->shop->orders, $this->shop->jobs->jobs));
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
        self::assertMatchesRegularExpression('/^e[a-f0-9]{32}$/', $this->store->facts[$order->number]->order->customerKey, 'a guest is told apart by a keyed hash, not by the email');
    }

    public function testADeletedOrderIsForgottenAndARebuildCountsEveryOrder(): void
    {
        $kept = $this->place(1);
        $deleted = $this->place(1);
        $this->shop->orders->cancel($deleted->number);
        $this->shop->orders->delete($deleted->number);
        self::assertSame([$kept->number], array_keys($this->store->facts));

        $this->store->facts = [];
        $this->shop->jobs->jobs->push(RebuildReports::TYPE, ['after' => 0]);
        self::assertSame([$kept->number], array_keys($this->store->facts));
    }

    public function testRefundedTaxCountsOnTheRefundsDayAndAReverseChargeSaleItsBase(): void
    {
        $vat = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxGroups->addRate($vat, new SaveTaxRate('BG', null, '20', 'VAT'));
        $this->shop->taxGroups->addRate($vat, new SaveTaxRate('DE', null, '19', 'MwSt'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::BillingAddress, $vat, reverseCharge: true));
        $this->shop->clock->modify('2030-03-30 12:00:00');
        $order = $this->place(2);
        $this->shop->orders->markPaidByShop($order->number, '', 7);
        $this->shop->clock->modify('+5 days');
        $this->shop->refunds->refund($order->number, new MakeRefund('9.6', [new RefundLine(0, 1)]), 7);

        // Here the report's job runs at once, inside the refund's transaction; a shop's runs after it, as this does.
        $this->shop->jobs->jobs->push(ProjectOrders::TYPE, ['order' => $order->number], (string) $order->number);
        $taxes = array_map(static fn($tax): array => [$tax->day->format('Y-m-d'), $tax->amount, $tax->refunded], $this->store->facts[$order->number]->taxes);
        self::assertSame([['2030-03-30', '3.2', '0'], ['2030-04-04', '0', '1.6']], $taxes, 'March charged it; April gave part back, and March stays as filed');

        $this->shop->vies->known = ['DE123456789' => 'Muster GmbH'];
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
        $business = $this->shop->checkout->placeOrder($owner, new PlaceOrder('max@example.de', new AddressData('Max', 'Muster', 'Hauptstr. 1', 'Berlin', '10115', 'DE', '+49 30 000000'), payment: 'bank_transfer', vatNumber: 'DE123456789'));
        self::assertSame([['DE-RC', '8', '0']], array_map(static fn($tax): array => [$tax->code, $tax->taxable, $tax->amount], $this->store->facts[$business->number]->taxes), 'its base, for the intra-EU supplies');
    }

    private function place(int $quantity): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20, quantity: $quantity))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
    }

    public function testEveryOrderOfABatchIsReportedOnceItsFactsAreWrittenAndEachRebuildStepAsItQueued(): void
    {
        $first = $this->place(1);
        $second = $this->place(2);
        $this->store->facts = [];

        $progress = new RecordedProgress();
        self::assertSame([], $this->shop->jobs->get(ProjectOrders::TYPE)->handle([self::job('first', ProjectOrders::TYPE, (string) $first->number), self::job('second', ProjectOrders::TYPE, (string) $second->number)], $progress->progress));
        self::assertSame(['first' => 'done', 'second' => 'done'], array_map(static fn(JobOutcome $outcome): string => $outcome->kind, $progress->outcomes), 'both reported after the one write');
        self::assertSame([$first->number, $second->number], array_keys($this->store->facts));

        $this->store->facts = [];
        $progress = new RecordedProgress();
        self::assertSame([], $this->shop->jobs->get(RebuildReports::TYPE)->handle([self::job('third', RebuildReports::TYPE, RebuildReports::KEY, ['after' => 0]), self::job('fourth', RebuildReports::TYPE, RebuildReports::KEY, ['after' => $first->number])], $progress->progress));
        self::assertSame(['third' => 'done', 'fourth' => 'done'], array_map(static fn(JobOutcome $outcome): string => $outcome->kind, $progress->outcomes), 'each step reported as its orders were queued');
        self::assertSame([$first->number, $second->number], array_keys($this->store->facts));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function job(string $id, string $type, ?string $key, array $payload = []): QueuedJob
    {
        return new QueuedJob($id, 'reports', $type, $key, $payload, 1, 1);
    }
}
