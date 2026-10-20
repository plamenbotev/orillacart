<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shipping;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SavePickupLocation;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * The shop's own pickup: customers choose one of its places at checkout, hear when the order is ready there,
 * and the shop says when they collected it.
 */
final class PickupTest extends TestCase
{
    private TestShop $shop;
    private int $pickup;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '2', stockMode: 'always_available'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->pickup = $this->shop->carriers->create(new SaveCarrier('Pickup', pickupLocations: [
            new SavePickupLocation('sofia', 'Sofia shop', '1 Vitosha Blvd, 1000 Sofia', 'Mon–Fri 9–18'),
            new SavePickupLocation('plovdiv', 'Plovdiv depot', '5 Main St, 4000 Plovdiv', '', '2'),
        ]))->id;
    }

    public function testCheckoutOffersEachPlaceWithItsFeeAndTheOrderGoesThere(): void
    {
        $owner = $this->cart();
        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG'));

        self::assertSame([["pickup:{$this->pickup}:sofia", 'Sofia shop', '0'], ["pickup:{$this->pickup}:plovdiv", 'Plovdiv depot', '2']], array_map(static fn($option): array => [$option->id, $option->name, $option->price], $summary->shippingOptions));
        self::assertSame(['1 Vitosha Blvd, 1000 Sofia', 'Mon–Fri 9–18'], [$summary->shippingOptions[0]->collectAt?->address, $summary->shippingOptions[0]->collectAt?->hours]);

        $placed = $this->shop->checkout->placeOrder($owner, $this->order('sofia'));
        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['sofia', 'Sofia shop, 1 Vitosha Blvd, 1000 Sofia (Mon–Fri 9–18)', 'Pickup – Sofia shop'], [$order->pickupPointId, $order->pickupPoint, $order->shipping]);
        $shipments = $this->shop->consignments->forOrder($placed->number);
        self::assertSame([false, false, 'Sofia shop, 1 Vitosha Blvd, 1000 Sofia (Mon–Fri 9–18)', false], [$shipments->bookable, $shipments->sendable, $shipments->pickup, $shipments->collectable], 'not paid yet');
    }

    public function testTheCustomerHearsItIsReadyAndCollectingItCompletesIt(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart(), $this->order('plovdiv'));
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('processing'), 7);
        self::assertTrue($this->shop->consignments->forOrder($placed->number)->collectable);

        $this->shop->consignments->readyForPickup($placed->number, 7);

        $mails = $this->shop->mail->summary();
        self::assertSame("ada@example.com: [Lamp Shop] Your order #{$placed->number} is ready to collect", end($mails));
        self::assertStringContainsString('Plovdiv depot, 5 Main St, 4000 Plovdiv', $this->shop->mail->emails[\count($mails) - 1]->html);
        self::assertContains('Ready to collect at Plovdiv depot, 5 Main St, 4000 Plovdiv.', array_column($this->shop->orders->order($placed->number)->notes, 'text'));

        $this->shop->consignments->pickedUp($placed->number, 7);
        self::assertSame('completed', $this->shop->orders->order($placed->number)->status);
        $this->expectException(ShippingRuleViolation::class);
        $this->shop->consignments->readyForPickup($placed->number, 7);
    }

    public function testAPickupHasNoRatesAndAnOrderThatShipsIsNotCollected(): void
    {
        try {
            $this->shop->carriers->create(new SaveCarrier('Both', rates: [new SaveShippingRate('Post', '4')], pickupLocations: [new SavePickupLocation('here', 'Here', 'Main St')]));
            self::fail('a pickup with rates');
        } catch (ShippingRuleViolation $e) {
            self::assertSame('orillacart_method_carrier_rates', $e->errorCode);
        }
        $post = $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Post', '4')]))->rates[0]->id;
        $placed = $this->shop->checkout->placeOrder($this->cart(), new PlaceOrder('ada@example.com', self::address(), shippingOption: "rate:{$post}", payment: 'bank_transfer'));

        $this->expectException(ShippingRuleViolation::class);
        $this->shop->consignments->pickedUp($placed->number, 7);
    }

    private function cart(): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
    }

    private function order(string $place): PlaceOrder
    {
        return new PlaceOrder('ada@example.com', self::address(), shippingOption: "pickup:{$this->pickup}:{$place}", payment: 'bank_transfer');
    }

    private static function address(): AddressData
    {
        return new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000');
    }
}
