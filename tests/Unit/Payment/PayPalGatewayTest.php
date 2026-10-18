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
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Domain\PaymentNotFound;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
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

    public function testMoneyThatDoesNotMatchTheOrderWaitsForTheShop(): void
    {
        $placed = $this->place();
        $this->shop->payPalApi->capturedAmount = '0.01';

        $this->shop->outcomes->returned('paypal', ['token' => 'PAYPAL-1'], false);

        self::assertSame('on_hold', $this->orderStatus($placed));
        self::assertSame('warning', $this->shop->logger->records[0]['level']);
    }

    public function testOnlyNotificationsPayPalSignedForTheShopAreRead(): void
    {
        $placed = $this->place();
        $this->shop->payPalApi->signed = false;

        try {
            $this->shop->outcomes->notified('paypal', $this->notification('CHECKOUT.ORDER.APPROVED', ['id' => 'PAYPAL-1']));
            self::fail('not signed');
        } catch (PaymentRuleViolation $e) {
            self::assertSame('orillacart_invalid_notification', $e->errorCode);
        }
        self::assertSame('pending_payment', $this->orderStatus($placed));
    }

    public function testACustomerWhoGivesUpCancelsThePaymentAndTheOrder(): void
    {
        $placed = $this->place();

        self::assertSame($placed->receiptUrl, $this->shop->outcomes->returned('paypal', ['token' => 'PAYPAL-1'], true));

        self::assertSame('cancelled', $this->orderStatus($placed));
        self::assertSame([], $this->shop->payPalApi->captured);
    }

    public function testACustomerComingBackWhilePayPalIsDownStillSeesTheReceipt(): void
    {
        $placed = $this->place();
        $this->shop->payPalApi->reachable = false;

        self::assertSame($placed->receiptUrl, $this->shop->outcomes->returned('paypal', ['token' => 'PAYPAL-1'], false));
        self::assertSame('pending_payment', $this->orderStatus($placed));

        $this->expectException(PaymentNotFound::class);
        $this->shop->outcomes->returned('paypal', ['token' => 'PAYPAL-404'], false);
    }

    private function place(): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'paypal'));
    }

    private function orderStatus(PlacedOrderView $placed): string
    {
        return $this->shop->orders->byKey($placed->key)->status;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function notification(string $type, array $resource): Notification
    {
        return new Notification(['paypal-transmission-id' => 'T-1'], (string) json_encode(['event_type' => $type, 'resource' => $resource]));
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
