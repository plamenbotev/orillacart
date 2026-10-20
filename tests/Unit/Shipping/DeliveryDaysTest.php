<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shipping;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\DeliveryDayView;
use OrillaCart\Shipping\Application\Dto\DeliverySlotView;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveDeliverySchedule;
use OrillaCart\Shipping\Application\Dto\SaveDeliverySlot;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Shipping\Domain\DeliverySchedule;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * A carrier lets the customer choose the day of delivery, and the time of day, at checkout.
 */
final class DeliveryDaysTest extends TestCase
{
    private TestShop $shop;
    private int $rate;

    protected function setUp(): void
    {
        // A Saturday, 15:00 in Sofia.
        $this->shop = new TestShop('2026-10-17 12:00:00 UTC');
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '2', stockMode: 'always_available'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $carrier = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Sofia', '5')], deliverySchedule: new SaveDeliverySchedule(
            [1, 2, 3, 4, 5],
            1,
            '14:00',
            7,
            ['2026-10-20'],
            [new SaveDeliverySlot('morning', '09:00', '12:00', 1), new SaveDeliverySlot('evening', '17:00', '20:00')],
            true,
        )));
        $this->rate = $carrier->rates[0]->id;
    }

    public function testTheDaysFollowTheLeadTheCutoffTheWeekdaysAndTheClosedDates(): void
    {
        $schedule = new DeliverySchedule([1, 2, 3, 4, 5], 1, '14:00', 7, ['2026-10-20']);
        $sofia = new \DateTimeZone('Europe/Sofia');

        self::assertSame(['2026-10-19', '2026-10-21', '2026-10-22', '2026-10-23'], $schedule->days(new \DateTimeImmutable('2026-10-17 15:00', $sofia)), 'after the cutoff on Saturday: from Monday, not Tuesday the 20th');
        self::assertSame(['2026-10-19', '2026-10-21', '2026-10-22', '2026-10-23'], $schedule->days(new \DateTimeImmutable('2026-10-16 13:59', $sofia)), 'before it on Friday: from Saturday, for seven days');
        self::assertSame(['2026-10-23'], new DeliverySchedule([5], 0, null, 7)->days(new \DateTimeImmutable('2026-10-17 09:00', $sofia)), 'only Fridays, from today');
        $this->expectException(ShippingRuleViolation::class);
        new DeliverySchedule([]);
    }

    public function testCheckoutOffersTheDaysAndTheOrderKeepsTheOneChosen(): void
    {
        $owner = $this->cart();
        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: "rate:{$this->rate}"));
        self::assertNotNull($summary->delivery);
        self::assertTrue($summary->delivery->required);
        self::assertSame(['2026-10-19', '2026-10-21', '2026-10-22', '2026-10-23'], array_map(static fn(DeliveryDayView $day): string => $day->date, $summary->delivery->days));
        self::assertSame(['2026-10-19', 'morning'], [$summary->deliveryDate, $summary->deliverySlot], 'the first day and slot, ticked');

        try {
            $this->shop->checkout->placeOrder($owner, $this->order(null, null));
            self::fail('the day is required');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_delivery_day_required', $e->errorCode);
        }
        $placed = $this->shop->checkout->placeOrder($owner, $this->order('2026-10-19', 'morning'));
        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['2026-10-19', '09:00–12:00'], [$order->deliveryDate, $order->deliverySlot]);
    }

    public function testASlotThatTookItsOrdersForTheDayIsFull(): void
    {
        $this->shop->checkout->placeOrder($this->cart(), $this->order('2026-10-19', 'morning'));

        $delivery = $this->shop->deliveryDays->forOption("rate:{$this->rate}");
        self::assertNotNull($delivery);
        self::assertSame([['morning', true], ['evening', false]], array_map(static fn(DeliverySlotView $slot): array => [$slot->id, $slot->full], $delivery->days[0]->slots));
        self::assertSame('evening', $this->shop->checkout->summary($this->cart(), new CheckoutDetails('BG', shippingOption: "rate:{$this->rate}", deliveryDate: '2026-10-19', deliverySlot: 'morning'))->deliverySlot, 'the open one, ticked');
        try {
            $this->shop->checkout->placeOrder($this->cart(), $this->order('2026-10-19', 'morning'));
            self::fail('full');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_delivery_slot_full', $e->errorCode);
        }
        try {
            $this->shop->checkout->placeOrder($this->cart(), $this->order('2026-10-20', 'evening'));
            self::fail('closed');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_delivery_day_unavailable', $e->errorCode);
        }
        self::assertSame('09:00–12:00', $this->shop->orders->order($this->shop->checkout->placeOrder($this->cart(), $this->order('2026-10-21', 'morning'))->number)->deliverySlot, 'another day');
    }

    public function testACarrierWithoutAScheduleOffersNoDay(): void
    {
        $post = $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Anywhere', '4')]))->rates[0]->id;

        self::assertNull($this->shop->checkout->summary($this->cart(), new CheckoutDetails('BG', shippingOption: "rate:{$post}"))->delivery);
        self::assertNull($this->shop->orders->order($this->shop->checkout->placeOrder($this->cart(), new PlaceOrder('ada@example.com', self::address(), shippingOption: "rate:{$post}", payment: 'bank_transfer', deliveryDate: '2026-10-19'))->number)->deliveryDate);
    }

    private function cart(): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
    }

    private function order(?string $date, ?string $slot): PlaceOrder
    {
        return new PlaceOrder('ada@example.com', self::address(), shippingOption: "rate:{$this->rate}", payment: 'bank_transfer', deliveryDate: $date, deliverySlot: $slot);
    }

    private static function address(): AddressData
    {
        return new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000');
    }
}
