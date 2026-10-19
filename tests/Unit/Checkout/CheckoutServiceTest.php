<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Domain\CartRuleViolation;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\PayOrder;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Domain\Event\OrderPlaced;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Domain\PaymentNotFound;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingClass;
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
        self::assertSame([['rate:' . $this->courier, 'Courier', 'Bulgaria', '5', '1', '6']], array_map(static fn($option): array => [$option->id, $option->carrier, $option->name, $option->price, $option->tax, $option->total], $summary->shippingOptions));
        self::assertSame(['cash_on_delivery', 'bank_transfer', 'paypal'], array_map(static fn($method): string => $method->gateway, $summary->paymentMethods));
        self::assertSame('Pay the courier.', $summary->paymentMethods[0]->instructions);
        self::assertSame(['bank_transfer', 'paypal'], array_map(static fn($method): string => $method->gateway, $this->shop->checkout->summary($owner, new CheckoutDetails('DE'))->paymentMethods));
    }

    public function testCashOnDeliveryPutsTheOrderOnHoldTakesTheStockAndEmptiesTheCart(): void
    {
        $owner = $this->cart([10, 2], [20, 1]);

        $placed = $this->shop->checkout->placeOrder($owner, $this->order('cash_on_delivery'));

        self::assertSame(['on_hold', '95.59', null], [$placed->status, $placed->total, $placed->redirectUrl]);
        self::assertSame('https://shop.test/?orillacart_order=' . $this->shop->receipts->issue($placed->number), $placed->receiptUrl);
        self::assertSame(1, $this->shop->stock->stock(StockItemRef::product(10))->available);
        self::assertSame(0, $this->shop->carts->cart($owner)->cart->items);
        self::assertSame(2, $this->shop->transactions->outermost, 'stock and order were saved together, then the order was put on hold');

        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['Cash on delivery', 'Have the cash ready.', 'Courier – Bulgaria', 'ada@example.com'], [$order->paymentName, $order->paymentNote, $order->shipping, $order->email]);
        self::assertSame(['Lamp', 2, '33.33', '66.66'], [$order->lines[0]->name, $order->lines[0]->quantity, $order->lines[0]->unitPrice, $order->lines[0]->total]);
        self::assertSame('Sofia', $order->shippingAddress?->city);
    }

    public function testAnOrderThatWouldCostOtherThanTheCustomerSawIsNotPlaced(): void
    {
        $owner = $this->cart([10, 2], [20, 1]);
        $seen = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: 'rate:' . $this->courier))->total;
        $this->shop->products->save(10, new SaveProduct('35', weight: '2.5'));

        try {
            $this->shop->checkout->placeOrder($owner, $this->order('cash_on_delivery', expectedTotal: $seen));
            self::fail('The lamp costs more than the customer saw.');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_cart_changed', $e->errorCode);
        }
        self::assertSame(3, $this->shop->stock->stock(StockItemRef::product(10))->available, 'nothing was taken');

        $now = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: 'rate:' . $this->courier))->total;
        self::assertSame(['95.59', '99.6'], [$seen, $now]);
        self::assertSame($now, $this->shop->checkout->placeOrder($owner, $this->order('cash_on_delivery', expectedTotal: $now))->total);
    }

    public function testTheLinesShareTheTaxBilledSoTheyAddUpToIt(): void
    {
        foreach ([40, 41, 42] as $product) {
            $this->shop->content->add($product, "Pin {$product}");
            $this->shop->products->save($product, new SaveProduct('0.33', 'virtual'));
        }

        $summary = $this->shop->checkout->summary($this->cart([40, 1], [41, 1], [42, 1]), new CheckoutDetails('BG'));

        // 20 % of 0.99 is 0.198, billed as 0.20; each line's own 0.066 would round to 0.07, 0.21 in all.
        self::assertSame('0.2', $summary->itemsTax);
        self::assertSame(['0.07', '0.07', '0.06'], array_map(static fn($line): string => $line->tax, $summary->lines));
    }

    public function testAFreeOrderNeedsNoPaymentAndGoesStraightToProcessing(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([30, 1]), $this->order(null, rate: null));

        self::assertSame(['processing', '0'], [$placed->status, $placed->total]);
        self::assertSame(1, $this->shop->transactions->outermost, 'placed and paid together');
        self::assertNull($this->shop->orders->order($placed->number)->shippingAddress, 'nothing ships');
    }

    public function testPayPalSendsTheCustomerToPayPalAndCapturesWhenTheyComeBack(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('paypal'));

        self::assertSame(['pending_payment', 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-1'], [$placed->status, $placed->redirectUrl]);
        $request = $this->shop->payPalApi->created[0];
        $payment = $this->shop->paymentRecords->findByReference('paypal', 'PAYPAL-1');
        self::assertNotNull($payment);
        self::assertSame(['46.00', 'EUR', $this->shop->paymentUrls->returnUrl('paypal', $payment->id()), $this->shop->paymentUrls->cancelUrl('paypal', $payment->id())], [$request->amount, $request->currency, $request->returnUrl, $request->cancelUrl]);
        foreach ([['token' => 'PAYPAL-1'], ['token' => 'PAYPAL-1', 'payment' => (string) $payment->id(), 'sig' => str_repeat('0', 32)], ['token' => 'PAYPAL-1'] + $this->shop->paymentUrls->query('paypal', $payment->id() + 1)] as $forged) {
            try {
                $this->shop->outcomes->returned('paypal', $forged, true);
                self::fail('a return address the shop did not sign for this payment');
            } catch (PaymentNotFound) {
            }
        }
        self::assertSame('pending_payment', $this->shop->orders->order($placed->number)->status, 'nothing was cancelled');

        self::assertSame($placed->receiptUrl, $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false));
        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['processing', 'CAPTURE-PAYPAL-1'], [$order->status, $this->shop->orderRecords->orders[$order->number]->transactionId()]);

        // Coming back twice, or a webhook after the return, changes nothing.
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);
        self::assertSame(['PAYPAL-1'], $this->shop->payPalApi->captured);
    }

    public function testComingBackFromTheCancelAddressAfterApprovingElsewhereStillPays(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('paypal'));
        // The customer approved in another tab, then went back to the first one and gave up there.
        $this->shop->payPalApi->orderStatus['PAYPAL-1'] = 'APPROVED';

        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), true);

        self::assertSame(['processing', ['PAYPAL-1']], [$this->shop->orders->order($placed->number)->status, $this->shop->payPalApi->captured]);
    }

    public function testGivingUpAtPayPalCancelsTheOrderAndPutsTheStockBack(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 3]), $this->order('paypal'));
        self::assertSame(0, $this->shop->stock->stock(StockItemRef::product(10))->available);

        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), true);

        self::assertSame('cancelled', $this->shop->orders->order($placed->number)->status);
        self::assertSame(3, $this->shop->stock->stock(StockItemRef::product(10))->available);
    }

    public function testADeclinedPaymentFailsTheOrderAndPutsTheStockBack(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('paypal'));
        $this->shop->payPalApi->captureStatus = 'DECLINED';

        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);

        self::assertSame('failed', $this->shop->orders->order($placed->number)->status);
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
        self::assertSame(1, $this->shop->carts->cart($owner)->cart->items, 'the customer keeps the cart');

        $this->shop->payPalApi->reachable = true;
        $placed = $this->shop->checkout->placeOrder($owner, $this->order('paypal'));

        self::assertSame('pending_payment', $placed->status);
        self::assertNotNull($placed->redirectUrl);
        self::assertSame(0, $this->shop->carts->cart($owner)->cart->items, 'emptied once the payment started');
    }

    public function testAGatewayThatBreaksIsShownAsUnavailableAndTheCustomerKeepsTheCart(): void
    {
        $this->shop->payPalApi->breaks = new \TypeError('A bug in the gateway.');
        $owner = $this->cart([10, 1]);

        try {
            $this->shop->checkout->placeOrder($owner, $this->order('paypal'));
            self::fail('the gateway broke');
        } catch (PaymentProviderUnavailable $e) {
            self::assertSame('orillacart_payment_provider_unavailable', $e->errorCode);
        }

        self::assertSame('cancelled', array_values($this->shop->orderRecords->orders)[0]->status()->value);
        self::assertSame(3, $this->shop->stock->stock(StockItemRef::product(10))->available);
        self::assertSame(1, $this->shop->carts->cart($owner)->cart->items);
        self::assertSame('error', $this->shop->logger->records[0]['level'] ?? null);
    }

    public function testACustomerComingBackWhilePayPalIsDownSeesTheReceipt(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('paypal'));
        $this->shop->payPalApi->reachable = false;

        self::assertSame($placed->receiptUrl, $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false));
        self::assertSame('pending_payment', $this->shop->orders->order($placed->number)->status, 'the webhook settles it later');
    }

    public function testAFailedPaymentIsTriedAgainLaterWithAnotherWay(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 2]), $this->order('paypal'));
        $this->shop->payPalApi->captureStatus = 'DECLINED';
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);
        self::assertSame(['failed', 3], [$this->shop->orders->order($placed->number)->status, $this->shop->stock->stock(StockItemRef::product(10))->available]);
        self::assertSame(['cash_on_delivery', 'bank_transfer', 'paypal'], array_map(static fn($method): string => $method->gateway, $this->shop->payNow->methods($this->shop->orders->order($placed->number))));

        $again = $this->shop->payNow->pay($this->shop->receipts->issue($placed->number), new PayOrder('bank_transfer'));

        self::assertSame(['on_hold', null], [$again->status, $again->redirectUrl]);
        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['Bank transfer', 'IBAN BG00 0000'], [$order->paymentName, $order->paymentNote]);
        self::assertSame(1, $this->shop->stock->stock(StockItemRef::product(10))->available, 'its items are taken again');
    }

    public function testAnOrderWaitingForItsMoneyIsPaidAnotherWayAndWaitsAnew(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('bank_transfer'));
        $this->shop->clock->modify('+5 hours');

        $paying = $this->shop->payNow->pay($this->shop->receipts->issue($placed->number), new PayOrder('paypal'));

        self::assertSame('pending_payment', $paying->status);
        self::assertNotNull($paying->redirectUrl);
        self::assertSame(0, $this->shop->orders->cancelAbandoned(), 'the payment window starts again');
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);
        self::assertSame('processing', $this->shop->orders->order($placed->number)->status);
        self::assertSame([], $this->shop->payNow->methods($this->shop->orders->order($placed->number)), 'a paid order is not paid again');
        try {
            $this->shop->payNow->pay($this->shop->receipts->issue($placed->number), new PayOrder('paypal'));
            self::fail('paid already');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_order_not_payable', $e->errorCode);
        }
    }

    public function testAFailedOrderWhoseItemsSoldOutCannotBePaid(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 2]), $this->order('paypal'));
        $this->shop->payPalApi->captureStatus = 'DECLINED';
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);
        $this->shop->checkout->placeOrder($this->cart([10, 3]), $this->order('bank_transfer'));

        try {
            $this->shop->payNow->pay($this->shop->receipts->issue($placed->number), new PayOrder('bank_transfer'));
            self::fail('sold out');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_out_of_stock', $e->errorCode);
        }
        self::assertSame('failed', $this->shop->orders->order($placed->number)->status);
    }

    public function testASecondSubmitWhileTheOrderIsBeingPlacedPlacesNoSecondOrder(): void
    {
        $owner = $this->cart([10, 1]);
        $second = null;
        $this->shop->payPalApi->whileCreating = function () use ($owner, &$second): void {
            $this->shop->payPalApi->whileCreating = null;
            try {
                $this->shop->checkout->placeOrder($owner, $this->order('paypal'));
            } catch (CheckoutRuleViolation $e) {
                $second = $e->errorCode;
            }
        };

        $placed = $this->shop->checkout->placeOrder($owner, $this->order('paypal'));

        self::assertSame('orillacart_order_in_progress', $second);
        self::assertCount(1, $this->shop->orderRecords->orders);
        self::assertNotNull($placed->redirectUrl);
        self::assertSame(2, $this->shop->stock->stock(StockItemRef::product(10))->available, 'stock taken once');
    }

    public function testACartLeftMarkedByARequestThatDiedCanBeOrderedFromAgain(): void
    {
        $owner = $this->cart([10, 1]);
        $cartId = $this->shop->carts->contents($owner)?->cartId;
        self::assertIsInt($cartId);
        $this->shop->transactions->run(fn() => $this->shop->carts->lockForOrder($cartId));
        $this->shop->carts->placing($cartId, 999);

        try {
            $this->shop->checkout->placeOrder($owner, $this->order('bank_transfer'));
            self::fail('an order is being placed');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_order_in_progress', $e->errorCode);
        }

        $this->shop->clock->modify('+3 minutes');
        self::assertSame('on_hold', $this->shop->checkout->placeOrder($owner, $this->order('bank_transfer'))->status);
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
        $this->shop->checkout->placeOrder($owner, new PlaceOrder($germanBilling->email, $germanBilling->billing, $this->order(null)->billing, 'rate:' . $this->courier, 'cash_on_delivery'));
    }

    public function testOrdersLeftUnpaidAtPayPalForThreeHoursAreCancelled(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 2]), $this->order('paypal'));
        $held = $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('bank_transfer'));

        $this->shop->clock->modify('+179 minutes');
        self::assertSame(0, $this->shop->orders->cancelAbandoned());
        $this->shop->clock->modify('+2 minutes');
        self::assertSame(1, $this->shop->orders->cancelAbandoned());

        self::assertSame(['cancelled', 'on_hold'], [$this->shop->orders->order($placed->number)->status, $this->shop->orders->order($held->number)->status]);
        self::assertSame(2, $this->shop->stock->stock(StockItemRef::product(10))->available);
    }

    public function testMoneyThatComesAfterTheOrderWasCancelledOpensItAgainWhileTheItemsAreThere(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 2]), $this->order('paypal'));
        $this->shop->clock->modify('+181 minutes');
        $this->shop->orders->cancelAbandoned();
        self::assertSame(3, $this->shop->stock->stock(StockItemRef::product(10))->available);

        // The customer approves at PayPal after all, and comes back.
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);

        $order = $this->shop->orders->order($placed->number);
        self::assertSame('processing', $order->status);
        self::assertNotNull($order->paidAt);
        self::assertSame(1, $this->shop->stock->stock(StockItemRef::product(10))->available, 'the items are taken again');
        self::assertSame([], $this->shop->payPalApi->refunds);

        // The order holds its stock again: refunding its items puts them back, once.
        $this->shop->refunds->refund($order->number, new MakeRefund($order->total, [new RefundLine(0, 2)], restock: true), null);
        self::assertSame(3, $this->shop->stock->stock(StockItemRef::product(10))->available);
    }

    public function testMoneyThatComesAfterTheItemsSoldOutGoesBack(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart([10, 2]), $this->order('paypal'));
        $this->shop->clock->modify('+181 minutes');
        $this->shop->orders->cancelAbandoned();
        $this->shop->checkout->placeOrder($this->cart([10, 3]), $this->order('bank_transfer'));

        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);

        $order = $this->shop->orders->order($this->shop->orders->order($placed->number)->number);
        self::assertSame('cancelled', $order->status);
        self::assertSame(0, $this->shop->stock->stock(StockItemRef::product(10))->available);
        self::assertCount(1, $this->shop->payPalApi->refunds, 'the capture went back');
        self::assertSame($order->total, array_values($this->shop->payPalApi->refunds)[0]['amount']);
        $notes = $order->notes;
        $note = array_pop($notes);
        self::assertNotNull($note);
        self::assertStringContainsString('given back in full', $note->text);
        self::assertTrue($note->forCustomer);
    }

    public function testTheShopTakesOrdersOnlyFromCountriesItSellsToAndNotAsACatalog(): void
    {
        $owner = $this->cart([10, 1]);
        $settings = $this->shop->settings->load();
        $this->shop->settings->save(new StoreSettings($settings->currency, 2, $settings->weightUnit, $settings->dimensionUnit, 'BG', sellTo: ['DE']));
        foreach ([
            fn() => $this->shop->checkout->placeOrder($owner, $this->order('bank_transfer')),
            function () use ($settings): void {
                $this->shop->settings->save(new StoreSettings($settings->currency, 2, $settings->weightUnit, $settings->dimensionUnit, 'BG', catalogOnly: true));
                $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('bank_transfer'));
            },
        ] as $index => $attempt) {
            try {
                $attempt();
                self::fail('Ordered anyway, attempt ' . $index);
            } catch (CartRuleViolation|CheckoutRuleViolation $e) {
                self::assertSame($index === 0 ? 'orillacart_country_not_served' : 'orillacart_catalog_only', $e->errorCode);
            }
        }
        self::assertSame(0, $this->shop->orderRecords->search(null, null, null, 0, 10)[1], 'no order');
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

    public function testABackorderSellsMoreThanIsInStockAndOwesTheRest(): void
    {
        $this->shop->products->save(10, new SaveProduct('33.33', weight: '2.5', backorders: 'notify'));
        $owner = $this->cart([10, 5]);

        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG'));
        self::assertSame([5, 2], [$summary->lines[0]->quantity, $summary->lines[0]->backordered], 'three in stock, two on backorder');
        self::assertSame(2, $this->shop->carts->cart($owner)->cart->lines[0]->backordered);

        $placed = $this->shop->checkout->placeOrder($owner, $this->order('cash_on_delivery'));
        $line = $this->shop->orders->order($placed->number)->lines[0];
        self::assertSame([5, 2, true], [$line->quantity, $line->backordered, $line->backorderShown]);
        self::assertSame([[$this->room, -2]], array_map(static fn($level): array => [$level->stockroomId, $level->quantity], $this->shop->stock->stock(StockItemRef::product(10))->levels));
        self::assertSame(0, $this->shop->stock->available([StockItemRef::product(10)]));

        $this->shop->orders->cancel($placed->number);
        self::assertSame(3, $this->shop->stock->available([StockItemRef::product(10)]), 'cancelled: nothing owed, the three back');
    }

    public function testABackorderTheCustomerIsNotToldOfIsStillRecordedForTheShop(): void
    {
        $this->shop->products->save(10, new SaveProduct('33.33', weight: '2.5', backorders: 'yes'));
        $owner = $this->cart([10, 4]);

        self::assertSame(0, $this->shop->checkout->summary($owner, new CheckoutDetails('BG'))->lines[0]->backordered);
        $line = $this->shop->orders->order($this->shop->checkout->placeOrder($owner, $this->order('cash_on_delivery'))->number)->lines[0];
        self::assertSame([1, false], [$line->backordered, $line->backorderShown]);
    }

    public function testWithoutBackordersTheCartStopsAtTheStock(): void
    {
        $this->expectException(CartRuleViolation::class);
        $this->cart([10, 4]);
    }

    public function testAProductsShippingClassChoosesTheRatesUntilTheClassIsDeleted(): void
    {
        $bulky = $this->shop->shippingClasses->create(new SaveShippingClass('Bulky'))->id;
        $this->shop->products->save(10, new SaveProduct('33.33', weight: '2.5', shippingClassId: $bulky));
        $freight = $this->shop->carriers->create(new SaveCarrier('Freight', rates: [new SaveShippingRate('Pallet', '40', shippingClasses: [$bulky])]))->id;
        $names = fn(): array => array_map(static fn($option): string => $option->name, $this->shop->checkout->summary($this->cart([10, 1]), new CheckoutDetails('BG'))->shippingOptions);

        self::assertSame(['Bulgaria', 'Pallet'], $names());
        $this->shop->carriers->update($freight, new SaveCarrier('Freight', rates: [new SaveShippingRate('Pallet', '40', shippingClasses: [0])]));
        self::assertSame(['Bulgaria'], $names());

        $this->shop->shippingClasses->delete($bulky);
        self::assertNull($this->shop->products->product(10)->shippingClassId);
        self::assertSame(['Bulgaria', 'Pallet'], $names(), 'now an item without a class');
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

    private function order(?string $payment, ?int $rate = -1, string $country = 'BG', ?string $expectedTotal = null): PlaceOrder
    {
        return new PlaceOrder(
            'ada@example.com',
            new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', $country, '+359 2 000 0000'),
            shippingOption: $rate === -1 ? 'rate:' . $this->courier : ($rate === null ? null : 'rate:' . $rate),
            payment: $payment,
            expectedTotal: $expectedTotal,
        );
    }
}
