<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Domain\Event\OrderPlaced;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class CheckoutServiceTest extends TestCase
{
    private TestShop $shop;
    private int $courier;
    private int $room;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $vat = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxGroups->addRate($vat, new SaveTaxRate('BG', null, '20', 'VAT'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $vat));

        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('33.33', weight: '2.5'));
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->content->add(30, 'Sticker');
        $this->shop->products->save(30, new SaveProduct('0', 'virtual'));
        $this->room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->room, 3)]));

        $this->courier = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Bulgaria', '5', regions: ['BG'])]))->rates[0]->id;
        $this->shop->paymentMethods->update('cash_on_delivery', new SavePaymentMethod('Cash on delivery', true, countries: ['BG'], instructions: 'Pay the courier.', receiptNote: 'Have the cash ready.'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true, 2, receiptNote: 'IBAN BG00 0000'));
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, 3, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'sandbox' => true]));
    }

    public function testTheSummaryShowsTaxesShippingAndThePaymentMethodsOfTheCountry(): void
    {
        $owner = $this->cart([10, 2], [20, 1]);

        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG'));

        self::assertSame(['74.66', '14.93', '5', '1', '95.59', 'EUR'], [$summary->subtotal, $summary->itemsTax, $summary->shipping, $summary->shippingTax, $summary->total, $summary->currency]);
        self::assertSame([['VAT', '20', '14.93', '1', '15.93']], array_map(static fn($tax): array => [$tax->name, $tax->percent, $tax->items, $tax->shipping, $tax->total], $summary->taxes));
        self::assertSame([[$this->courier, 'Courier', 'Bulgaria', '5', '1', '6']], array_map(static fn($option): array => [$option->rateId, $option->carrier, $option->name, $option->price, $option->tax, $option->total], $summary->shippingOptions));
        self::assertSame(['cash_on_delivery', 'bank_transfer', 'paypal'], array_map(static fn($method): string => $method->gateway, $summary->paymentMethods));
        self::assertSame('Pay the courier.', $summary->paymentMethods[0]->instructions);
        self::assertSame(['bank_transfer', 'paypal'], array_map(static fn($method): string => $method->gateway, $this->shop->checkout->summary($owner, new CheckoutDetails('DE'))->paymentMethods));
    }

    public function testCashOnDeliveryPutsTheOrderOnHoldTakesTheStockAndEmptiesTheCart(): void
    {
        $owner = $this->cart([10, 2], [20, 1]);

        $placed = $this->shop->checkout->placeOrder($owner, $this->order('cash_on_delivery'));

        self::assertSame(['on_hold', '95.59', null], [$placed->status, $placed->total, $placed->redirectUrl]);
        self::assertSame("https://shop.test/?orillacart_order={$placed->key}", $placed->receiptUrl);
        self::assertSame(1, $this->shop->stock->stock(StockItemRef::product(10))->available);
        self::assertSame(0, $this->shop->carts->cart($owner)->cart->items);
        self::assertSame(1, $this->shop->transactions->runs, 'stock and order were saved together');

        $order = $this->shop->orders->byKey($placed->key);
        self::assertSame(['Cash on delivery', 'Have the cash ready.', 'Courier – Bulgaria', 'ada@example.com'], [$order->paymentName, $order->paymentNote, $order->shipping, $order->email]);
        self::assertSame(['Lamp', 2, '33.33', '66.66'], [$order->lines[0]->name, $order->lines[0]->quantity, $order->lines[0]->unitPrice, $order->lines[0]->total]);
        self::assertSame('Sofia', $order->shippingAddress?->city);
    }

    public function testAFreeOrderNeedsNoPaymentAndGoesStraightToProcessing(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([30, 1]), $this->order(null, rate: null));

        self::assertSame(['processing', '0'], [$placed->status, $placed->total]);
        self::assertNull($this->shop->orders->byKey($placed->key)->shippingAddress, 'nothing ships');
    }

    public function testPayPalSendsTheCustomerToPayPalAndCapturesWhenTheyComeBack(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('paypal'));

        self::assertSame(['pending_payment', 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-1'], [$placed->status, $placed->redirectUrl]);
        $request = $this->shop->payPalApi->created[0];
        self::assertSame(['46.00', 'EUR', 'https://shop.test/payments/paypal/return', 'https://shop.test/payments/paypal/cancel'], [$request->amount, $request->currency, $request->returnUrl, $request->cancelUrl]);

        self::assertSame($placed->receiptUrl, $this->shop->payPal->approve('PAYPAL-1'));
        $order = $this->shop->orders->byKey($placed->key);
        self::assertSame(['processing', 'CAPTURE-PAYPAL-1'], [$order->status, $this->shop->orderRecords->orders[$order->number]->transactionId()]);

        // Coming back twice, or a webhook after the return, changes nothing.
        $this->shop->payPal->approve('PAYPAL-1');
        self::assertSame(['PAYPAL-1'], $this->shop->payPalApi->captured);
    }

    public function testGivingUpAtPayPalCancelsTheOrderAndPutsTheStockBack(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 3]), $this->order('paypal'));
        self::assertSame(0, $this->shop->stock->stock(StockItemRef::product(10))->available);

        $this->shop->payPal->cancel('PAYPAL-1');

        self::assertSame('cancelled', $this->shop->orders->byKey($placed->key)->status);
        self::assertSame(3, $this->shop->stock->stock(StockItemRef::product(10))->available);
    }

    public function testADeclinedPaymentFailsTheOrderAndPutsTheStockBack(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('paypal'));
        $this->shop->payPalApi->captureStatus = 'DECLINED';

        $this->shop->payPal->approve('PAYPAL-1');

        self::assertSame('failed', $this->shop->orders->byKey($placed->key)->status);
        self::assertSame(3, $this->shop->stock->stock(StockItemRef::product(10))->available);
    }

    public function testWhenPayPalCannotBeReachedTheOrderIsCancelledAndTheCustomerCanTryAgain(): void
    {
        $this->shop->payPalApi->reachable = false;
        $owner = $this->cart([10, 1]);

        try {
            $this->shop->checkout->placeOrder($owner, $this->order('paypal'));
            self::fail('PayPal is down');
        } catch (PaymentProviderUnavailable $e) {
            self::assertSame('orillacart_payment_provider_unavailable', $e->errorCode);
        }

        self::assertSame('cancelled', array_values($this->shop->orderRecords->orders)[0]->status()->value);
        self::assertSame(3, $this->shop->stock->stock(StockItemRef::product(10))->available);
    }

    public function testStockIsCheckedAgainWhenTheOrderIsPlaced(): void
    {
        $owner = $this->cart([10, 3]);
        $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('bank_transfer'));

        try {
            $this->shop->checkout->placeOrder($owner, $this->order('bank_transfer'));
            self::fail('only two lamps are left');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_cart_changed', $e->errorCode);
        }
        self::assertCount(1, $this->shop->orderRecords->orders);
    }

    public function testShippingAndPaymentMustBeOnesThatAreOffered(): void
    {
        $owner = $this->cart([10, 1]);
        foreach ([
            'orillacart_shipping_required' => $this->order('bank_transfer', rate: null),
            'orillacart_payment_required' => $this->order(null),
        ] as $code => $order) {
            try {
                $this->shop->checkout->placeOrder($owner, $order);
                self::fail($code);
            } catch (CheckoutRuleViolation $e) {
                self::assertSame($code, $e->errorCode);
            }
        }

        // Cash on delivery is for customers billed in Bulgaria only, wherever the order ships.
        $germanBilling = $this->order('cash_on_delivery', country: 'DE');
        $this->expectExceptionMessage('Choose how to pay.');
        $this->shop->checkout->placeOrder($owner, new PlaceOrder($germanBilling->email, $germanBilling->billing, $this->order(null)->billing, $this->courier, 'cash_on_delivery'));
    }

    public function testOrdersLeftUnpaidAtPayPalForAnHourAreCancelled(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 2]), $this->order('paypal'));
        $held = $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('bank_transfer'));

        $this->shop->clock->modify('+59 minutes');
        self::assertSame(0, $this->shop->orders->cancelAbandoned());
        $this->shop->clock->modify('+2 minutes');
        self::assertSame(1, $this->shop->orders->cancelAbandoned());

        self::assertSame(['cancelled', 'on_hold'], [$this->shop->orders->byKey($placed->key)->status, $this->shop->orders->byKey($held->key)->status]);
        self::assertSame(2, $this->shop->stock->stock(StockItemRef::product(10))->available);
    }

    public function testAnEmptyCartCannotBeOrdered(): void
    {
        $this->expectException(CheckoutRuleViolation::class);

        $this->shop->checkout->placeOrder(new CartOwner(null), $this->order('bank_transfer'));
    }

    public function testPlacingAnOrderIsAnnounced(): void
    {
        $placed = [];
        $this->shop->events->addListener(OrderPlaced::class, static function (OrderPlaced $event) use (&$placed): void {
            $placed[] = $event->orderId;
        });

        $order = $this->shop->checkout->placeOrder($this->cart([20, 1]), $this->order('bank_transfer', rate: null));

        self::assertSame([$order->number], $placed);
    }

    /**
     * @param array{int, int} ...$lines product id, quantity
     */
    private function cart(array ...$lines): CartOwner
    {
        $owner = new CartOwner(null);
        foreach ($lines as [$product, $quantity]) {
            $owner = new CartOwner($this->shop->carts->add($owner, new AddToCart($product, quantity: $quantity))->newToken ?? $owner->token);
        }

        return $owner;
    }

    private function order(?string $payment, ?int $rate = -1, string $country = 'BG'): PlaceOrder
    {
        return new PlaceOrder(
            'ada@example.com',
            new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', $country, '+359 2 000 0000'),
            rateId: $rate === -1 ? $this->courier : $rate,
            payment: $payment,
        );
    }
}
