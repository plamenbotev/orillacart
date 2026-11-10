<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Payment;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Domain\PaymentNotFound;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Payment\Domain\PaymentStatus;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Payment\PayPalNotifications;
use PHPUnit\Framework\TestCase;

final class PayPalGatewayTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1']));
    }

    public function testAWebhookSettlesTheOrderOfACustomerWhoNeverCameBack(): void
    {
        $placed = $this->place();

        $this->shop->outcomes->notified('paypal', $this->notification('CHECKOUT.ORDER.APPROVED', ['id' => 'PAYPAL-1']));

        self::assertSame('processing', $this->orderStatus($placed));
        self::assertSame(['PAYPAL-1'], $this->shop->payPalApi->captured);
    }

    public function testAnApprovalOfAPayPalOrderTheShopDidNotStartCapturesNothing(): void
    {
        $placed = $this->place();

        // Another site on the same PayPal account (a staging copy) started PAYPAL-9.
        $this->shop->outcomes->notified('paypal', $this->notification('CHECKOUT.ORDER.APPROVED', ['id' => 'PAYPAL-9']));

        self::assertSame([], $this->shop->payPalApi->captured);
        self::assertSame('pending_payment', $this->orderStatus($placed));
    }

    public function testAPaymentForAnOrderTheShopMarkedPaidIsKeptAndNoted(): void
    {
        $placed = $this->place();
        $number = $this->shop->orders->order($placed->number)->number;
        $this->shop->orders->markPaidByShop($number, '', 7);

        $this->shop->outcomes->notified('paypal', $this->notification('CHECKOUT.ORDER.APPROVED', ['id' => 'PAYPAL-1']));

        $order = $this->shop->orders->order($number);
        self::assertSame('processing', $order->status);
        self::assertSame('CAPTURE-PAYPAL-1', $order->transactionId);
        $notes = $order->notes;
        self::assertStringContainsString('already marked paid', array_pop($notes)->text ?? '');
    }

    public function testAReversedOrDisputedPaymentFlagsItsOrderForTheShop(): void
    {
        $placed = $this->place();
        $this->shop->outcomes->notified('paypal', $this->notification('CHECKOUT.ORDER.APPROVED', ['id' => 'PAYPAL-1']));
        $number = $this->shop->orders->order($placed->number)->number;

        $this->shop->outcomes->notified('paypal', $this->notification('CUSTOMER.DISPUTE.CREATED', ['reason' => 'MERCHANDISE_OR_SERVICE_NOT_RECEIVED', 'status' => 'OPEN', 'disputed_transactions' => [['seller_transaction_id' => 'CAPTURE-PAYPAL-1']]]));
        $this->shop->outcomes->notified('paypal', $this->capture('PAYMENT.CAPTURE.REVERSED', 'REVERSED', 'PAYPAL-1', '8.00'));
        // PayPal sends a notification again when it is not sure it arrived.
        $this->shop->outcomes->notified('paypal', $this->capture('PAYMENT.CAPTURE.REVERSED', 'REVERSED', 'PAYPAL-1', '8.00'));

        $order = $this->shop->orders->order($number);
        self::assertSame('processing', $order->status, 'the status stays');
        self::assertSame("The customer opened a dispute at PayPal (MERCHANDISE_OR_SERVICE_NOT_RECEIVED OPEN).\nPayPal reversed the payment, as for a chargeback the customer's bank made.", $order->attention);
        self::assertTrue($this->shop->orders->orders(new OrderQuery(attention: true), forShop: true)->orders[0]->needsAttention ?? false);
        self::assertFalse($this->shop->orders->orders(new OrderQuery(attention: true))->orders[0]->needsAttention ?? true, 'a customer\'s list does not show it');
        self::assertCount(2, array_filter($this->shop->mail->summary(), static fn(string $mail): bool => str_contains($mail, 'needs your attention')));
    }

    public function testPaymentsNobodyToldTheShopOfAreAskedAboutEveryHour(): void
    {
        $approved = $this->place();
        $held = $this->place();
        $this->shop->payPalApi->captureStatus = 'PENDING';
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-2', 'paypal'), false);
        self::assertSame('on_hold', $this->orderStatus($held));
        $this->shop->payPalApi->captureStatus = 'COMPLETED';
        // The customer of PAYPAL-1 approved and closed the window; no webhook came.
        $this->shop->payPalApi->orderStatus['PAYPAL-1'] = 'APPROVED';

        self::assertSame(0, $this->shop->outcomes->reconcile(), 'not before a quarter of an hour');
        $this->shop->clock->modify('+16 minutes');
        self::assertSame(2, $this->shop->outcomes->reconcile());

        self::assertSame(['processing', 'processing'], [$this->orderStatus($approved), $this->orderStatus($held)]);
        self::assertSame(0, $this->shop->outcomes->reconcile(), 'settled ones are not asked again');
    }

    public function testAShopChargingFillerIsNotOfferedPayPalUntilItPricesInWholeForints(): void
    {
        $this->shop->settings->save(new StoreSettings(new Currency('HUF'), null, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'HU'));

        self::assertSame('PayPal takes amounts in HUF with 0 decimals; set the shop’s price decimals to 0.', $this->shop->paymentMethods->show('paypal')->notReady);
        self::assertSame([], $this->shop->paymentOptions->available('HU', 'HUF'));

        $this->shop->settings->save(new StoreSettings(new Currency('HUF'), 0, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'HU'));

        self::assertNull($this->shop->paymentMethods->show('paypal')->notReady);
        self::assertSame(['paypal'], array_map(static fn($option): string => $option->gateway, $this->shop->paymentOptions->available('HU', 'HUF')));
    }

    public function testLivePaymentsNeedTheWebhook(): void
    {
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => '']));
        self::assertSame('Enter the webhook ID: live payments need it.', $this->shop->paymentMethods->show('paypal')->notReady);

        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'sandbox' => true]));
        self::assertNull($this->shop->paymentMethods->show('paypal')->notReady, 'test payments do without it');
    }

    public function testCaptureEventsCompleteFailOrHoldThePayment(): void
    {
        $completed = $this->place();
        $this->shop->outcomes->notified('paypal', $this->capture('PAYMENT.CAPTURE.COMPLETED', 'COMPLETED', 'PAYPAL-1', '8.00'));
        self::assertSame('processing', $this->orderStatus($completed));

        $held = $this->place();
        $this->shop->outcomes->notified('paypal', $this->capture('PAYMENT.CAPTURE.PENDING', 'PENDING', 'PAYPAL-2', '8.00'));
        self::assertSame('on_hold', $this->orderStatus($held));
        $this->shop->clock->modify('+2 hours');
        $this->shop->orders->cancelAbandoned();
        self::assertSame('on_hold', $this->orderStatus($held), 'a payment under review is not abandoned');
        $this->shop->outcomes->notified('paypal', $this->capture('PAYMENT.CAPTURE.COMPLETED', 'COMPLETED', 'PAYPAL-2', '8.00'));
        self::assertSame('processing', $this->orderStatus($held));

        $denied = $this->place();
        $this->shop->outcomes->notified('paypal', $this->capture('PAYMENT.CAPTURE.DENIED', 'DENIED', 'PAYPAL-3', '8.00'));
        self::assertSame('pending_payment', $this->orderStatus($denied), 'DENIED is not a status the shop knows: nothing changes');
        $this->shop->outcomes->notified('paypal', $this->capture('PAYMENT.CAPTURE.DECLINED', 'DECLINED', 'PAYPAL-3', '8.00'));
        self::assertSame('failed', $this->orderStatus($denied));
    }

    public function testMarkingAHeldPaymentPaidCompletesItWithItsTransaction(): void
    {
        $held = $this->place();
        self::assertNull($this->shop->payPalApi->created[0]->shipTo, 'an e-book ships nowhere');
        $this->shop->outcomes->notified('paypal', $this->capture('PAYMENT.CAPTURE.PENDING', 'PENDING', 'PAYPAL-1', '8.00'));

        // The shop saw the money arrive at PayPal before PayPal said so.
        $this->shop->orders->markPaidByShop($held->number, '', 7);

        $payment = $this->shop->paymentRecords->findByReference('paypal', 'PAYPAL-1');
        self::assertSame(['processing', 'CAPTURE-PAYPAL-1'], [$this->orderStatus($held), $this->shop->orderRecords->orders[$held->number]->transactionId()]);
        self::assertSame(PaymentStatus::Completed, $payment?->status());
        $notes = \count($this->shop->orders->order($held->number)->notes);
        $this->shop->outcomes->notified('paypal', $this->capture('PAYMENT.CAPTURE.COMPLETED', 'COMPLETED', 'PAYPAL-1', '8.00'));
        self::assertCount($notes, $this->shop->orders->order($held->number)->notes, 'PayPal\'s word after it changes nothing');
    }

    public function testMoneyThatDoesNotMatchTheOrderWaitsForTheShop(): void
    {
        $placed = $this->place();
        $this->shop->payPalApi->capturedAmount = '0.01';

        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);

        self::assertSame('on_hold', $this->orderStatus($placed));
        self::assertSame('warning', $this->shop->logger->records[0]['level']);
    }

    public function testAWebhookPayPalSignedIsVerifiedAgainstItsCertificateWithoutAskingPayPal(): void
    {
        $placed = $this->place();

        $this->shop->outcomes->notified('paypal', $this->notification('CHECKOUT.ORDER.APPROVED', ['id' => 'PAYPAL-1']));

        self::assertSame('processing', $this->orderStatus($placed));
        self::assertSame(0, $this->shop->payPalApi->verifications);
        self::assertSame([PayPalNotifications::CERTIFICATE_URL], $this->shop->payPalApi->fetchedCertificates);
    }

    public function testOnlyNotificationsPayPalSignedForTheShopAreRead(): void
    {
        $placed = $this->place();
        $approved = (string) json_encode(['id' => 'WH-X', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'PAYPAL-1']]);
        $signed = PayPalNotifications::of($this->shop, $approved);
        $refused = [
            'signed for another webhook' => PayPalNotifications::of($this->shop, $approved, 'WH-2'),
            'a body PayPal did not sign' => new Notification($signed->headers, str_replace('WH-X', 'WH-Y', $approved)),
            'another algorithm' => PayPalNotifications::of($this->shop, $approved, headers: ['paypal-auth-algo' => 'SHA1withRSA']),
            'a certificate elsewhere' => PayPalNotifications::of($this->shop, $approved, headers: ['paypal-cert-url' => 'https://attacker.test/v1/notifications/certs/CERT-1']),
            'a certificate on a look-alike host' => PayPalNotifications::of($this->shop, $approved, headers: ['paypal-cert-url' => 'https://api.paypal.com.attacker.test/v1/notifications/certs/CERT-1']),
            'the sandbox\'s certificate for a live shop' => PayPalNotifications::of($this->shop, $approved, headers: ['paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1']),
            'in the clear' => PayPalNotifications::of($this->shop, $approved, headers: ['paypal-cert-url' => 'http://api.paypal.com/v1/notifications/certs/CERT-1']),
            'sent a week ago' => PayPalNotifications::of($this->shop, $approved, headers: ['paypal-transmission-time' => $this->shop->clock->now()->modify('-7 days')->format('Y-m-d\\TH:i:s\\Z')]),
            'no signature' => PayPalNotifications::of($this->shop, $approved, headers: ['paypal-transmission-sig' => '']),
            'no headers' => new Notification(['paypal-transmission-id' => 'T-1'], $approved),
        ];

        foreach ($refused as $why => $notification) {
            try {
                $this->shop->outcomes->notified('paypal', $notification);
                self::fail($why);
            } catch (PaymentRuleViolation $e) {
                self::assertSame('orillacart_invalid_notification', $e->errorCode, $why);
            }
        }
        self::assertSame('pending_payment', $this->orderStatus($placed));
        self::assertSame([], $this->shop->payPalApi->captured);
        self::assertSame(0, $this->shop->payPalApi->verifications, 'PayPal was never asked');
        self::assertSame(array_fill(0, 2, PayPalNotifications::CERTIFICATE_URL), $this->shop->payPalApi->fetchedCertificates, 'only for what was in PayPal\'s shape');
    }

    public function testRefusedWebhooksAreLoggedInTheSameWordsAndOnlyAFewTimesAnHour(): void
    {
        $this->place();
        $this->shop->rateLimiter->limits['paypal_refused_notifications'] = 2;

        for ($i = 0; $i < 4; ++$i) {
            try {
                $this->shop->outcomes->notified('paypal', PayPalNotifications::signed($this->shop, 'CHECKOUT.ORDER.APPROVED', ['id' => 'PAYPAL-1'], 'WH-' . $i . '-other'));
            } catch (PaymentRuleViolation) {
            }
        }

        $refusals = array_values(array_filter($this->shop->logger->records, static fn(array $record): bool => str_starts_with($record['message'], 'A PayPal webhook was refused')));
        self::assertSame(['A PayPal webhook was refused: {why}.', 'A PayPal webhook was refused: {why}.'], array_column($refusals, 'message'));
    }

    public function testACertificateThatIsNotPayPalsHasPayPalAskedAFewTimesAnHourForTheWholeSite(): void
    {
        $placed = $this->place();
        $this->shop->rateLimiter->limits['paypal_verifications'] = 1;
        $denied = $this->notification('PAYMENT.CAPTURE.DENIED', []);
        $notification = $this->notification('CHECKOUT.ORDER.APPROVED', ['id' => 'PAYPAL-1']);
        // PayPal's address answers with something that is not its certificate.
        $this->shop->payPalApi->certificates[PayPalNotifications::CERTIFICATE_URL] = 'not a certificate';

        $this->shop->outcomes->notified('paypal', $denied);
        self::assertSame(1, $this->shop->payPalApi->verifications, 'PayPal verified it');

        try {
            $this->shop->outcomes->notified('paypal', $notification);
            self::fail('asked PayPal past the limit');
        } catch (PaymentRuleViolation $e) {
            self::assertSame('orillacart_payment_provider_unavailable', $e->errorCode, 'PayPal sends it again later');
        }
        self::assertSame(1, $this->shop->payPalApi->verifications);
        self::assertSame('pending_payment', $this->orderStatus($placed));
    }

    public function testThePayPalIdsCarryTheSitesMarkSoACopyOfTheShopNeverCollidesWithIt(): void
    {
        $placed = $this->place();
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);

        $mark = substr(hash('sha256', $this->shop->paymentUrls->notificationUrl('paypal')), 0, 12);
        $request = $this->shop->payPalApi->created[0];
        self::assertSame(["orillacart-{$mark}-payment-1", "orillacart-{$mark}-{$placed->number}-1"], [$request->requestId, $request->invoiceId]);
        self::assertSame(["orillacart-{$mark}-capture-PAYPAL-1"], $this->shop->payPalApi->captureRequests);
    }

    public function testACustomerWhoGivesUpCancelsThePaymentNotTheOrder(): void
    {
        $placed = $this->place();

        self::assertSame($placed->receiptUrl, $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), true));

        self::assertSame('pending_payment', $this->orderStatus($placed), 'it may still be paid, until the time for unpaid orders runs out');
        self::assertSame([], $this->shop->payPalApi->captured);
    }

    public function testAPaymentMadeInTheSandboxIsNotAskedAboutLive(): void
    {
        $placed = $this->place();
        // Made live; the shop then turns the sandbox on to try something.
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'sandbox' => true]));
        $this->shop->payPalApi->orderStatus['PAYPAL-1'] = 'VOIDED';
        $this->shop->clock->modify('+1 hour');

        self::assertSame(0, $this->shop->outcomes->reconcile(), 'the sandbox would not know it, and read it as given up');
        self::assertSame('pending_payment', $this->orderStatus($placed));
    }

    public function testACustomerComingBackWhilePayPalIsDownStillSeesTheReceipt(): void
    {
        $placed = $this->place();
        $this->shop->payPalApi->reachable = false;

        self::assertSame($placed->receiptUrl, $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false));
        self::assertSame('pending_payment', $this->orderStatus($placed));

        $this->expectException(PaymentNotFound::class);
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-404', 'paypal'), false);
    }

    private function place(): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'paypal'));
    }

    private function orderStatus(PlacedOrderView $placed): string
    {
        return $this->shop->orders->order($placed->number)->status;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function notification(string $type, array $resource): Notification
    {
        return PayPalNotifications::signed($this->shop, $type, $resource);
    }

    private function capture(string $type, string $status, string $order, string $amount): Notification
    {
        return $this->notification($type, [
            'id' => "CAPTURE-{$order}",
            'status' => $status,
            'amount' => ['value' => $amount, 'currency_code' => 'EUR'],
            'supplementary_data' => ['related_ids' => ['order_id' => $order]],
        ]);
    }
}
