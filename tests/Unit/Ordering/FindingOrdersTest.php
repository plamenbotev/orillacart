<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Ordering;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\OrderMark;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Shipping\Domain\Consignment;
use OrillaCart\Shipping\Domain\ParcelTracking;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * A shop's people find the order a customer calls about by whatever they have at hand, and narrow the list by
 * what the orders are.
 */
final class FindingOrdersTest extends TestCase
{
    private TestShop $shop;
    private int $rate;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('40', weight: '1', sku: 'LAMP-BRASS', stockMode: 'always_available'));
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual', sku: 'BOOK-1', stockMode: 'always_available'));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Europe', '5', regions: ['BG', 'GR'])]))->rates[0]->id;
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testTheSearchFindsAnOrderByAddressLineAndWhatOtherAreasKeep(): void
    {
        $lamp = $this->place(10, 'ada@example.com', new AddressData('Ada', 'Lovelace', '12 Analytical Row', 'Sofia', '1404', 'BG', '+359 2 000 0000'));
        $book = $this->place(20, 'bo@example.com', new AddressData('Bo', 'Peep', '3 Shepherd Lane', 'Athens', '10552', 'GR', '+30 21 0000 0000'));
        $this->shop->consignmentRecords->save(Consignment::sent($lamp, 'Speedy', [new ParcelTracking('SPD-778812'), new ParcelTracking('SPD-778813')], new \DateTimeImmutable(), [0 => 1]));
        $this->shop->transactions->run(fn() => $this->shop->invoices->invoice($book));
        $invoice = $this->shop->invoices->ofOrder($book)[0]->number;

        self::assertSame([$lamp], $this->found(new OrderQuery(search: 'analytical')), 'the street');
        self::assertSame([$book], $this->found(new OrderQuery(search: '10552')), 'the postcode');
        self::assertSame([$lamp], $this->found(new OrderQuery(search: 'lamp-brass')), 'a line\'s SKU');
        self::assertSame([$book], $this->found(new OrderQuery(search: 'e-book')), 'a line\'s name');
        self::assertSame([$lamp], $this->found(new OrderQuery(search: '778813')), 'the second parcel\'s tracking number');
        self::assertSame([$book], $this->found(new OrderQuery(search: $invoice)), 'the invoice number');
        self::assertSame([], $this->found(new OrderQuery(search: 'zz')), 'too short to look up elsewhere, and matching nothing here');
        self::assertSame([], array_map(static fn($order): int => $order->number, $this->shop->orders->orders(new OrderQuery(search: '778813'))->orders), 'a customer\'s own list does not look elsewhere');
    }

    public function testTheSearchFindsTheOrdersThatUsedACouponAndTheFilterTheOrdersThatUsedAny(): void
    {
        $this->shop->coupons->create(new SaveCoupon('SPRING25', 'percent', '10', 'Spring'));
        $owner = $this->cart(20);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('SPRING25'));
        $with = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', self::address(), payment: 'bank_transfer'))->number;
        $without = $this->place(20, 'bo@example.com', self::address());

        self::assertSame([$with], $this->found(new OrderQuery(search: 'spring2')));
        self::assertSame([$with], $this->found(new OrderQuery(has: 'coupon')));
        self::assertNotContains($without, $this->found(new OrderQuery(has: 'coupon')));
        self::assertSame([], $this->found(new OrderQuery(has: 'unknown_mark')), 'a mark no area gives finds nothing');
    }

    public function testFiltersNarrowByStatusesPaymentShippingCountryDeliveryAndWhatTheOrderHas(): void
    {
        $unpaid = $this->place(10, 'ada@example.com', self::address(), note: 'Ring twice.');
        $paid = $this->place(10, 'bo@example.com', new AddressData('Bo', 'Peep', '3 Shepherd Lane', 'Athens', '10552', 'GR', '+30 21 0000 0000'));
        $this->shop->orders->markPaidByShop($paid, '', 7);
        $shipped = $this->place(10, 'cy@example.com', self::address());
        $this->shop->orders->markPaidByShop($shipped, '', 7);
        $this->shop->orders->changeStatus($shipped, new ChangeOrderStatus('shipped'), 7);
        $refunded = $this->place(20, 'di@example.com', self::address());
        $this->shop->orders->markPaidByShop($refunded, '', 7);
        $this->shop->refunds->refund($refunded, new MakeRefund('8', outside: true), 7);
        $partly = $this->place(20, 'ed@example.com', self::address());
        $this->shop->orders->markPaidByShop($partly, '', 7);
        $this->shop->refunds->refund($partly, new MakeRefund('3', outside: true), 7);

        self::assertSame([$partly, $shipped, $paid], $this->found(new OrderQuery(status: 'processing,shipped')), 'a partly refunded order is still processing');
        self::assertSame([$unpaid], $this->found(new OrderQuery(paid: 'unpaid')));
        self::assertSame([$shipped, $paid], $this->found(new OrderQuery(paid: 'paid')));
        self::assertSame([$partly], $this->found(new OrderQuery(paid: 'partly_refunded')));
        self::assertSame([$refunded], $this->found(new OrderQuery(paid: 'refunded')));
        self::assertSame([$shipped], $this->found(new OrderQuery(shipped: 'yes')));
        self::assertSame([$paid, $unpaid], $this->found(new OrderQuery(shipped: 'no')), 'e-books do not ship');
        self::assertSame([$paid], $this->found(new OrderQuery(country: 'GR')));
        self::assertSame([$unpaid], $this->found(new OrderQuery(has: 'note')));
        self::assertSame([$shipped, $paid, $unpaid], $this->found(new OrderQuery(shipping: 'Courier – Europe')));
        self::assertSame([$shipped], $this->found(new OrderQuery(status: 'shipped', country: 'BG', shipped: 'yes')), 'filters add up');

        $facets = $this->shop->orders->facets();
        self::assertSame([['Courier – Europe'], ['BG', 'GR'], ['EUR']], [$facets->shipping, $facets->countries, $facets->currencies]);
        self::assertContains(['value' => 'coupon', 'label' => 'Used a coupon'], $facets->has);
        self::assertContains(['value' => 'return', 'label' => 'A return'], $facets->has);
    }

    public function testAnAreasMarkNarrowsTheList(): void
    {
        $first = $this->place(20, 'ada@example.com', self::address());
        $second = $this->place(20, 'bo@example.com', self::address());
        $this->shop->orderLookups->add(new class ($second) implements OrderMark {
            public function __construct(private int $order) {}

            public function key(): string
            {
                return 'gift_wrapped';
            }

            public function label(): string
            {
                return 'Gift wrapped';
            }

            public function orderIds(): array
            {
                return [$this->order];
            }
        });

        self::assertSame([$second], $this->found(new OrderQuery(has: 'gift_wrapped')));
        self::assertSame([], $this->found(new OrderQuery(has: 'gift_wrapped,note')), 'each thing it has narrows');
        self::assertSame([$second, $first], $this->found(new OrderQuery()));
    }

    /**
     * @return list<int> order numbers, newest first, as the shop's list finds them
     */
    private function found(OrderQuery $query): array
    {
        return array_map(static fn($order): int => $order->number, $this->shop->orders->orders($query, forShop: true)->orders);
    }

    private function place(int $product, string $email, AddressData $address, string $note = '', ?string $vatNumber = null): int
    {
        return $this->shop->checkout->placeOrder($this->cart($product), new PlaceOrder($email, $address, shippingOption: $product === 10 ? "rate:{$this->rate}" : null, payment: 'bank_transfer', note: $note, vatNumber: $vatNumber))->number;
    }

    private function cart(int $product): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart($product))->newToken);
    }

    private static function address(): AddressData
    {
        return new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000');
    }
}
