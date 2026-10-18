<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shipping;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\EditOrderItems;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Extension\Shipping\Tracking;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\BookShipment;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Shipping\Application\TrackConsignments;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Extension\TestShipMethod;
use PHPUnit\Framework\TestCase;

/**
 * A courier's add-on at work: pickup points at checkout, then booking, labelling, cancelling and tracking the
 * shipment from the order.
 */
final class ConsignmentsTest extends TestCase
{
    private TestShop $shop;
    private TestShipMethod $courier;
    private int $carrier;

    protected function setUp(): void
    {
        $this->courier = new TestShipMethod();
        $this->shop = new TestShop(shippingMethods: [$this->courier]);
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '2', stockMode: 'always_available'));
        $this->carrier = $this->shop->carriers->create(new SaveCarrier('Speedy', method: TestShipMethod::ID, methodSettings: ['account' => 'A-1']))->id;
        $this->shop->paymentMethods->update('cash_on_delivery', new SavePaymentMethod('Cash on delivery', true));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testAServiceToAnOfficeAsksTheCustomerToChooseOne(): void
    {
        $owner = $this->cart();
        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: $this->option('office'), shippingPostcode: '1000'));
        self::assertSame(['SOF-1', 'SOF-2'], array_map(static fn($point): string => $point->id, $summary->pickupPoints));
        self::assertSame([], $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: $this->option('express')))->pickupPoints, 'a service to the door');

        try {
            $this->shop->checkout->placeOrder($owner, $this->order('office', 'cash_on_delivery'));
            self::fail('no office chosen');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_pickup_point_required', $e->errorCode);
        }
        $placed = $this->shop->checkout->placeOrder($owner, $this->order('office', 'cash_on_delivery', 'SOF-2'));
        $order = $this->shop->orders->byKey($placed->key);
        self::assertSame(['SOF-2', 'Locker Mall, 101 Bulgaria Blvd, 1404 Sofia'], [$order->pickupPointId, $order->pickupPoint]);
    }

    public function testAnOrderOnHoldMovedToAnOfficeServiceNeedsAnOfficeAndKeepsIt(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart(), $this->order('economy', 'bank_transfer'));
        self::assertSame(['SOF-1', 'SOF-2'], array_map(static fn($point): string => $point->id, $this->shop->editing->pickupPoints($placed->number, $this->option('office'))));

        try {
            $this->shop->editing->edit($placed->number, new EditOrderItems([new EditedItem(10, 1)], $this->option('office')), 7);
            self::fail('no office');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_pickup_point_required', $e->errorCode);
        }
        self::assertSame('SOF-2', $this->shop->editing->edit($placed->number, new EditOrderItems([new EditedItem(10, 1)], $this->option('office'), 'SOF-2'), 7)->pickupPointId);
        self::assertSame('SOF-2', $this->shop->editing->edit($placed->number, new EditOrderItems([new EditedItem(10, 2)], $this->option('office')), 7)->pickupPointId, 'kept');
        self::assertNull($this->shop->editing->edit($placed->number, new EditOrderItems([new EditedItem(10, 2)], $this->option('express')), 7)->pickupPointId, 'to the door again');
    }

    public function testTheShopBooksTheShipmentPrintsItsLabelAndCanCancelItBeforeThePickUp(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart(), $this->order('office', 'cash_on_delivery', 'SOF-1'));
        self::assertSame(['bookable' => true, 'carrier' => 'Speedy'], ['bookable' => $this->shop->consignments->forOrder($placed->number)->bookable, 'carrier' => $this->shop->consignments->forOrder($placed->number)->carrier]);

        $booked = $this->shop->consignments->book($placed->number, new BookShipment(2), 7);
        self::assertSame(['TS1', 'https://track.test/TS1', 'booked'], [$booked->number, $booked->trackingUrl, $booked->status]);
        $request = $this->courier->booked[0];
        self::assertSame(['office', 'SOF-1', $placed->total, 2, '2'], [$request->service, $request->pickupPointId, $request->cashOnDelivery, $request->parcels, $request->contents->weight()], 'the courier collects the unpaid total');
        self::assertContains('Shipment TS1 booked with Speedy.', array_map(static fn($note): string => $note->text, $this->shop->orders->order($placed->number)->notes));

        try {
            $this->shop->consignments->book($placed->number, new BookShipment(), 7);
            self::fail('one is on its way');
        } catch (ShippingRuleViolation $e) {
            self::assertSame('orillacart_shipment_exists', $e->errorCode);
        }
        $label = $this->shop->consignments->label($booked->id);
        self::assertSame(['label-TS1.pdf', '%PDF-1.4 label of TS1'], [$label->filename, base64_decode($label->content, true)]);

        self::assertSame('cancelled', $this->shop->consignments->cancel($booked->id, 7)->status);
        $again = $this->shop->consignments->book($placed->number, new BookShipment(), 7);
        $this->courier->pickedUp[] = $again->number;
        $this->expectException(ShippingRuleViolation::class);
        $this->shop->consignments->cancel($again->id, 7);
    }

    public function testAShippingMethodThatBreaksIsAReadableRefusal(): void
    {
        $order = $this->order('office', 'cash_on_delivery', 'SOF-1');
        $placed = $this->shop->checkout->placeOrder($this->cart(), new PlaceOrder($order->email, new AddressData('Ada', 'Lovelace', 'Bug Street', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: $order->shippingOption, payment: $order->payment, pickupPoint: $order->pickupPoint));

        try {
            $this->shop->consignments->book($placed->number, new BookShipment(), 7);
            self::fail('the method broke');
        } catch (ShippingRuleViolation $e) {
            self::assertSame(['orillacart_courier_failed', 'The shipping method failed: An add-on bug.'], [$e->errorCode, $e->getMessage()]);
        }
        self::assertTrue($this->shop->consignments->forOrder($placed->number)->bookable, 'nothing was booked');
    }

    public function testTrackingFollowsTheShipmentAndADeliveryCompletesTheShippedOrder(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart(), $this->order('express', 'bank_transfer'));
        $booked = $this->shop->consignments->book($placed->number, new BookShipment(), 7);
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('processing'), 7);
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('shipped'), 7);
        self::assertStringContainsString('https://track.test/TS1', $this->shop->mail->emails[\count($this->shop->mail->emails) - 1]->html, 'the shipped email links the tracking');

        self::assertSame([$booked->id], $this->shop->consignments->onTheWay());
        self::assertTrue($this->shop->consignments->track($booked->id));
        self::assertFalse($this->shop->consignments->track($booked->id), 'nothing new');
        self::assertSame(['on_the_way', 'At the depot'], [$this->shop->consignments->forOrder($placed->number)->shipments[0]->status, $this->shop->consignments->forOrder($placed->number)->shipments[0]->tracking]);

        $this->courier->tracking[$booked->number] = new Tracking(Tracking::DELIVERED, 'Delivered to the customer');
        $this->shop->jobs->jobs->push(TrackConsignments::TYPE, [], (string) $booked->id);
        self::assertSame('completed', $this->shop->orders->order($placed->number)->status);
    }

    public function testAnOrderShippedByTheShopsOwnRatesHasNothingToBook(): void
    {
        $rate = $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Anywhere', '4')]))->rates[0]->id;
        $owner = $this->cart();
        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', self::address(), shippingOption: "rate:{$rate}", payment: 'bank_transfer'));

        self::assertFalse($this->shop->consignments->forOrder($placed->number)->bookable);
        $this->expectException(ShippingRuleViolation::class);
        $this->shop->consignments->book($placed->number, new BookShipment(), 7);
    }

    private function cart(): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
    }

    private function option(string $service): string
    {
        return "carrier:{$this->carrier}:{$service}";
    }

    private function order(string $service, string $payment, ?string $pickupPoint = null): PlaceOrder
    {
        return new PlaceOrder('ada@example.com', self::address(), shippingOption: $this->option($service), payment: $payment, pickupPoint: $pickupPoint);
    }

    private static function address(): AddressData
    {
        return new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000');
    }
}
