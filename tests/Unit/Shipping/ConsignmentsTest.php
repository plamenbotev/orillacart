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
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderContact;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shipping\Application\Dto\BookShipment;
use OrillaCart\Shipping\Application\Dto\OrderShipmentsView;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveParcel;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Shipping\Application\Dto\SendShipment;
use OrillaCart\Shipping\Application\Dto\ShipItem;
use OrillaCart\Shipping\Application\Dto\ShipmentArrived;
use OrillaCart\Shipping\Application\Dto\ShippedItemView;
use OrillaCart\Shipping\Application\Dto\TestShippingSettings;
use OrillaCart\Shipping\Application\ShippingMethods;
use OrillaCart\Shipping\Application\TrackConsignments;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Extension\TestShipMethod;
use OrillaCart\Tests\Fixtures\Jobs\RecordedProgress;
use OrillaCart\Tests\Fixtures\Payment\FixedExtensions;
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

    public function testTheCouriersSettingsAreTriedAtTheCourier(): void
    {
        self::assertTrue(new ShippingMethods(new FixedExtensions([], [$this->courier]))->methods()[0]->testable);
        self::assertTrue($this->shop->carriers->test(TestShipMethod::ID, new TestShippingSettings(['account' => 'A-1'], $this->carrier))->works);
        $refused = $this->shop->carriers->test(TestShipMethod::ID, new TestShippingSettings(['account' => 'B-2']));
        self::assertSame([false, 'The courier does not know this account.'], [$refused->works, $refused->problem]);
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
        $order = $this->shop->orders->order($placed->number);
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
        $mails = $this->shop->mail->summary();
        self::assertSame("ada@example.com: [Lamp Shop] Follow your order #{$placed->number}", end($mails), 'the customer can follow it');
        self::assertStringContainsString('href="https://track.test/TS1"', $this->shop->mail->emails[\count($mails) - 1]->html);

        try {
            $this->shop->consignments->book($placed->number, new BookShipment(), 7);
            self::fail('everything is in a shipment');
        } catch (ShippingRuleViolation $e) {
            self::assertSame('orillacart_nothing_to_ship', $e->errorCode);
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
        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['shipped', null], [$order->status, $order->paidAt], 'arriving does not pay a bank transfer');
        self::assertStringContainsString('The order is not paid yet, so it stays shipped', $order->notes[\count($order->notes) - 1]->text);
    }

    public function testABookedShipmentTheCourierHasNotCollectedLeavesTheOrderAndCanStillBeCancelled(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart(), $this->order('express', 'bank_transfer'));
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('processing'), 7);
        $booked = $this->shop->consignments->book($placed->number, new BookShipment(), 7);
        $this->courier->tracking[$booked->number] = new Tracking(Tracking::BOOKED, 'Waiting for the courier');

        self::assertTrue($this->shop->consignments->track($booked->id), 'the courier\'s words are kept');
        self::assertSame(['booked', 'Waiting for the courier'], [$this->shop->consignments->forOrder($placed->number)->shipments[0]->status, $this->shop->consignments->forOrder($placed->number)->shipments[0]->tracking]);
        self::assertSame('processing', $this->shop->orders->order($placed->number)->status, 'not shipped yet');

        $this->courier->tracking[$booked->number] = new Tracking(Tracking::ON_THE_WAY, 'At the depot');
        $this->shop->consignments->track($booked->id);
        $this->courier->tracking[$booked->number] = new Tracking(Tracking::BOOKED, 'Booked');
        $this->shop->consignments->track($booked->id);
        self::assertSame('on_the_way', $this->shop->consignments->forOrder($placed->number)->shipments[0]->status, 'a late "booked" does not take it back');
    }

    public function testADeliveryCompletesAnOrderPaidOnDeliveryAndRecordsItsPaymentInFull(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart(), $this->order('express', 'cash_on_delivery'));
        $booked = $this->shop->consignments->book($placed->number, new BookShipment(), 7);
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('processing'), 7);
        $this->courier->tracking[$booked->number] = new Tracking(Tracking::DELIVERED, 'Delivered to the customer');
        $this->shop->jobs->jobs->push(TrackConsignments::TYPE, [], (string) $booked->id);

        $order = $this->shop->orders->order($placed->number);
        self::assertSame('completed', $order->status);
        self::assertNotNull($order->paidAt);
        self::assertSame($order->total, $order->refundable, 'the cash came: it can be refunded');
    }

    public function testTheCouriersFirstScanShipsTheOrderAndADeliveryOrAReturnFollows(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart(), $this->order('express', 'bank_transfer'));
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('processing'), 7);
        $booked = $this->shop->consignments->book($placed->number, new BookShipment(), 7);
        self::assertSame('processing', $this->shop->orders->order($placed->number)->status, 'booked is not shipped');
        $sent = \count($this->shop->mail->emails);

        $this->shop->consignments->track($booked->id);

        self::assertSame('shipped', $this->shop->orders->order($placed->number)->status);
        self::assertStringContainsString('https://track.test/TS1', $this->shop->mail->emails[$sent]->html, 'the customer hears it left, with the tracking');

        $returned = $this->shop->checkout->placeOrder($this->cart(), $this->order('express', 'bank_transfer'));
        $this->shop->orders->changeStatus($returned->number, new ChangeOrderStatus('processing'), 7);
        $back = $this->shop->consignments->book($returned->number, new BookShipment(), 7);
        $this->courier->tracking[$back->number] = new Tracking(Tracking::RETURNED, 'Refused by the recipient');
        $this->shop->consignments->track($back->id);
        self::assertContains("The courier is bringing shipment {$back->number} back: Refused by the recipient", array_column($this->shop->orders->order($returned->number)->notes, 'text'));
    }

    public function testABookedOrShippedOrderKeepsItsAddressButItsPhoneAndEmailChange(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart(), $this->order('express', 'bank_transfer'));
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('processing'), 7);
        $booked = $this->shop->consignments->book($placed->number, new BookShipment(), 7);
        $elsewhere = new AddressData('Ada', 'Lovelace', '9 Shipka St', 'Sofia', '1000', 'BG', '+359 2 000 0000');
        $newPhone = new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 88 000 0000');

        $refused = function (AddressData $address) use ($placed): string {
            try {
                $this->shop->orders->changeContact($placed->number, new ChangeOrderContact('ada@example.com', $address, $address), 7);
            } catch (RuleViolation $e) {
                return $e->errorCode;
            }

            return 'changed';
        };
        self::assertSame('orillacart_order_shipped', $refused($elsewhere), 'the parcel is booked to the old address');
        self::assertSame('changed', $refused($newPhone));
        self::assertSame('+359 88 000 0000', $this->shop->orders->order($placed->number)->shippingAddress?->phone);

        $this->shop->consignments->cancel($booked->id, 7);
        self::assertSame('changed', $refused($elsewhere), 'once the booking is cancelled');
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('shipped'), 7);
        self::assertSame('orillacart_order_shipped', $refused(self::address()), 'nor once it shipped');
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

    public function testAnOrderGoesInSeveralShipmentsAndShipsOnceNothingIsLeft(): void
    {
        $this->shop->content->add(20, 'Shade');
        $this->shop->products->save(20, new SaveProduct('10', weight: '0.5', stockMode: 'always_available'));
        $owner = $this->cart(2);
        $this->shop->carts->add($owner, new AddToCart(20));
        $placed = $this->shop->checkout->placeOrder($owner, $this->order('express', 'cash_on_delivery'));
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('processing'), 7);
        $left = static fn(OrderShipmentsView $shipments): array => array_map(static fn(ShippedItemView $item): array => [$item->name, $item->quantity], $shipments->left);
        self::assertSame([['Lamp', 2], ['Shade', 1]], $left($this->shop->consignments->forOrder($placed->number)));

        $first = $this->shop->consignments->book($placed->number, new BookShipment(1, [new ShipItem(0, 1)]), 7);
        self::assertSame([['Lamp', 1]], array_map(static fn($item): array => [$item->name, $item->quantity], $first->items));
        self::assertSame(['2', $placed->total], [$this->courier->booked[0]->contents->weight(), $this->courier->booked[0]->cashOnDelivery], 'one lamp; the courier collects the total with the first');
        self::assertContains('Shipment TS1 booked with Speedy, with 1 of the 3 items.', array_column($this->shop->orders->order($placed->number)->notes, 'text'));
        try {
            $this->shop->consignments->book($placed->number, new BookShipment(1, [new ShipItem(0, 2)]), 7);
            self::fail('one lamp is left');
        } catch (ShippingRuleViolation $e) {
            self::assertSame(['orillacart_nothing_to_ship', '1 of "Lamp" are left to send.'], [$e->errorCode, $e->getMessage()]);
        }
        $this->shop->consignments->track($first->id);
        self::assertSame('processing', $this->shop->orders->order($placed->number)->status, 'the rest has not gone');

        $rest = $this->shop->consignments->book($placed->number, new BookShipment(), 7);
        self::assertSame([['Lamp', 1], ['Shade', 1]], array_map(static fn($item): array => [$item->name, $item->quantity], $rest->items));
        self::assertNull($this->courier->booked[1]->cashOnDelivery, 'collected once');
        self::assertSame([], $left($this->shop->consignments->forOrder($placed->number)));
        $this->shop->consignments->track($rest->id);
        self::assertSame('shipped', $this->shop->orders->order($placed->number)->status);

        $this->courier->tracking[$first->number] = new Tracking(Tracking::DELIVERED);
        $this->shop->consignments->track($first->id);
        self::assertSame('shipped', $this->shop->orders->order($placed->number)->status, 'one parcel is still on its way');
        $this->courier->tracking[$rest->number] = new Tracking(Tracking::DELIVERED);
        $this->shop->consignments->track($rest->id);
        self::assertSame('completed', $this->shop->orders->order($placed->number)->status);
    }

    public function testTheShopSendsAShipmentItselfWithEachParcelsTrackingNumber(): void
    {
        $rate = $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Anywhere', '4')]))->rates[0]->id;
        $placed = $this->shop->checkout->placeOrder($this->cart(3), new PlaceOrder('ada@example.com', self::address(), shippingOption: "rate:{$rate}", payment: 'bank_transfer'));
        $this->shop->orders->markPaidByShop($placed->number, '', 7);
        $shipments = $this->shop->consignments->forOrder($placed->number);
        self::assertSame([false, true], [$shipments->bookable, $shipments->sendable]);

        $sent = $this->shop->consignments->send($placed->number, new SendShipment('Bulgarian Post', [new SaveParcel('PS1', 'https://post.test/PS1'), new SaveParcel('PS2', 'https://post.test/PS2')], [new ShipItem(0, 2)]), 7);
        self::assertSame(['PS1', 'https://post.test/PS1', 'on_the_way', true], [$sent->number, $sent->trackingUrl, $sent->status, $sent->sentByShop]);
        self::assertSame([['number' => 'PS1', 'tracking_url' => 'https://post.test/PS1'], ['number' => 'PS2', 'tracking_url' => 'https://post.test/PS2']], $sent->parcels);
        self::assertSame('processing', $this->shop->orders->order($placed->number)->status, 'one lamp is left');
        $mails = $this->shop->mail->summary();
        self::assertSame("ada@example.com: [Lamp Shop] Follow your order #{$placed->number}", end($mails));
        self::assertSame([], $this->shop->consignments->onTheWay(), 'no courier to ask');

        $last = $this->shop->consignments->send($placed->number, new SendShipment('Bulgarian Post', [new SaveParcel('PS3')]), 7);
        self::assertSame('shipped', $this->shop->orders->order($placed->number)->status);

        $this->shop->consignments->arrived($sent->id, new ShipmentArrived(), 7);
        self::assertSame('shipped', $this->shop->orders->order($placed->number)->status);
        $this->shop->consignments->arrived($last->id, new ShipmentArrived(), 7);
        self::assertSame('completed', $this->shop->orders->order($placed->number)->status);
        $this->expectException(ShippingRuleViolation::class);
        $this->shop->consignments->arrived($last->id, new ShipmentArrived('returned'), 7);
    }

    public function testAShipmentThatCameBackLeavesItsItemsToSendAgain(): void
    {
        $rate = $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Anywhere', '4')]))->rates[0]->id;
        $placed = $this->shop->checkout->placeOrder($this->cart(), new PlaceOrder('ada@example.com', self::address(), shippingOption: "rate:{$rate}", payment: 'bank_transfer'));
        $sent = $this->shop->consignments->send($placed->number, new SendShipment('Post', [new SaveParcel('PS1')]), 7);

        $this->shop->consignments->arrived($sent->id, new ShipmentArrived('returned'), 7);

        self::assertSame([['Lamp', 1]], array_map(static fn($item): array => [$item->name, $item->quantity], $this->shop->consignments->forOrder($placed->number)->left));
        self::assertContains('Shipment PS1 came back.', array_column($this->shop->orders->order($placed->number)->notes, 'text'));
    }

    private function cart(int $quantity = 1): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: $quantity))->newToken);
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

    public function testEachShipmentIsReportedAsItsCourierAnsweredAndOneTheCourierCannotPlaceWaitsAlone(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart(), $this->order('express', 'bank_transfer'));
        $first = $this->shop->consignments->book($placed->number, new BookShipment(), 7);
        $second = $this->shop->consignments->book($this->shop->checkout->placeOrder($this->cart(), $this->order('express', 'bank_transfer'))->number, new BookShipment(), 7);
        $this->courier->trackingDown = $second->number;
        $job = static fn(string $id, int $consignment): QueuedJob => new QueuedJob($id, 'shipping', TrackConsignments::TYPE, (string) $consignment, [], 1, 1);

        $progress = new RecordedProgress();
        self::assertSame([], $this->shop->jobs->get(TrackConsignments::TYPE)->handle([$job('first', $first->id), $job('second', $second->id), $job('third', 999)], $progress->progress));

        self::assertSame(['first' => 'done', 'second' => 'retry', 'third' => 'done'], array_map(static fn(JobOutcome $outcome): string => $outcome->kind, $progress->outcomes), 'the first was settled before the second was asked; a shipment gone is nothing to ask about');
        self::assertStringContainsString('The tracking service is down.', $progress->outcomes['second']->error);
        self::assertSame('on_the_way', $this->shop->consignments->forOrder($placed->number)->shipments[0]->status);
    }
}
