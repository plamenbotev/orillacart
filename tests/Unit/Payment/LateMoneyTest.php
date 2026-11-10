<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Payment;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Domain\PaymentStatus;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Payment\PayPalNotifications;
use PHPUnit\Framework\TestCase;

/**
 * Money for a payment the shop had ended: none is taken where the shop is the one to take it, and what a provider
 * took all the same reaches the order.
 */
final class LateMoneyTest extends TestCase
{
    private const string STRIPE_SECRET = 'whsec_shop';

    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->shop->stock->setLevels(StockItemRef::product(20), new SetStockLevels([new SetStockLevel($room, 2)]));
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1']));
        $this->shop->paymentMethods->update('stripe', new SavePaymentMethod('Card', true, 2, settings: ['secret_key' => 'sk_live_shop', 'webhook_secret' => self::STRIPE_SECRET]));
    }

    public function testACustomerWhoGaveUpAndApprovesAtPayPalAfterAllIsNotCharged(): void
    {
        $placed = $this->place('paypal');
        // "Cancel and return" at PayPal, where the order was never approved.
        $this->shop->payPalApi->orderStatus['PAYPAL-1'] = 'CREATED';
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), true);
        self::assertSame(PaymentStatus::Cancelled, $this->shop->paymentRecords->findByReference('paypal', 'PAYPAL-1')?->status());

        // Back in the browser to PayPal's page, approved there: PayPal tells the shop, and sends the customer back.
        $this->shop->outcomes->notified('paypal', $this->payPal('CHECKOUT.ORDER.APPROVED', ['id' => 'PAYPAL-1']));
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);

        self::assertSame([], $this->shop->payPalApi->captured, 'no money is taken for a payment the shop gave up on');
        self::assertSame('pending_payment', $this->shop->orders->order($placed->number)->status, 'the order still waits to be paid');
    }

    public function testAnApprovalAfterPayPalDeclinedThePaymentIsNotCapturedAgain(): void
    {
        $placed = $this->place('paypal');
        $this->shop->payPalApi->captureStatus = 'DECLINED';
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);
        self::assertSame(PaymentStatus::Failed, $this->shop->paymentRecords->findByReference('paypal', 'PAYPAL-1')?->status());

        $this->shop->payPalApi->captureStatus = 'COMPLETED';
        $this->shop->outcomes->notified('paypal', $this->payPal('CHECKOUT.ORDER.APPROVED', ['id' => 'PAYPAL-1']));

        self::assertSame(['PAYPAL-1'], $this->shop->payPalApi->captured, 'only the capture that was declined');
        self::assertNull($this->shop->orders->order($placed->number)->paidAt);
    }

    public function testMoneyPayPalTookForAPaymentThatHadFailedPaysTheWaitingOrder(): void
    {
        $placed = $this->place('paypal');
        $this->shop->payPalApi->captureStatus = 'DECLINED';
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);

        // PayPal took the money after all, and says so.
        $this->shop->outcomes->notified('paypal', $this->payPalCapture('COMPLETED', 'PAYPAL-1', '8.00'));

        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['processing', 'CAPTURE-PAYPAL-1'], [$order->status, $order->transactionId]);
        $payment = $this->shop->paymentRecords->findByReference('paypal', 'PAYPAL-1');
        self::assertSame([PaymentStatus::Completed, 'CAPTURE-PAYPAL-1'], [$payment?->status(), $payment?->transactionId()], 'the money has a payment to be refunded from');
    }

    public function testMoneyForAPaymentTheCustomerGaveUpOpensTheCancelledOrderAgain(): void
    {
        $placed = $this->place('paypal');
        $this->shop->payPalApi->orderStatus['PAYPAL-1'] = 'CREATED';
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), true);
        $this->shop->clock->modify('+181 minutes');
        self::assertSame(1, $this->shop->orders->cancelAbandoned());
        self::assertSame(2, $this->shop->stock->stock(StockItemRef::product(20))->available);

        $this->shop->outcomes->notified('paypal', $this->payPalCapture('COMPLETED', 'PAYPAL-1', '8.00'));

        $order = $this->shop->orders->order($placed->number);
        self::assertSame('processing', $order->status);
        self::assertNotNull($order->paidAt);
        self::assertSame(1, $this->shop->stock->stock(StockItemRef::product(20))->available, 'the item is taken again');
        self::assertSame([], $this->shop->payPalApi->refunds);
    }

    public function testMoneyForAnEndedPaymentWhoseItemsSoldOutSinceGoesBack(): void
    {
        $placed = $this->place('paypal');
        $this->shop->payPalApi->orderStatus['PAYPAL-1'] = 'CREATED';
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), true);
        $this->shop->clock->modify('+181 minutes');
        $this->shop->orders->cancelAbandoned();
        $this->place('stripe', 2);

        $this->shop->outcomes->notified('paypal', $this->payPalCapture('COMPLETED', 'PAYPAL-1', '8.00'));

        $order = $this->shop->orders->order($placed->number);
        self::assertSame('cancelled', $order->status);
        self::assertCount(1, $this->shop->payPalApi->refunds, 'the capture went back');
        self::assertSame(['capture' => 'CAPTURE-PAYPAL-1', 'amount' => '8.00', 'currency' => 'EUR'], array_values($this->shop->payPalApi->refunds)[0]);
    }

    public function testLateMoneyOfAnotherAmountIsTheShopsToLookAt(): void
    {
        $placed = $this->place('paypal');
        $this->shop->payPalApi->captureStatus = 'DECLINED';
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);

        $this->shop->outcomes->notified('paypal', $this->payPalCapture('COMPLETED', 'PAYPAL-1', '5.00'));

        $order = $this->shop->orders->order($placed->number);
        self::assertNull($order->paidAt, 'not taken for the order');
        self::assertStringContainsString('had ended unpaid (transaction CAPTURE-PAYPAL-1)', (string) $order->attention);
        self::assertSame(PaymentStatus::Failed, $this->shop->paymentRecords->findByReference('paypal', 'PAYPAL-1')?->status());
    }

    public function testMoneyStripeTookForASessionTheCustomerLeftPaysTheOrder(): void
    {
        $placed = $this->place('stripe');
        $this->shop->outcomes->returned('stripe', $this->shop->paymentUrls->query('stripe', 1), true);
        self::assertSame(PaymentStatus::Cancelled, $this->shop->paymentRecords->findByReference('stripe', 'cs_test_1')?->status());

        // The session was paid in another tab as the shop closed it.
        $session = $this->shop->stripeApi->complete('cs_test_1');
        $session['payment_intent'] = 'pi_1';
        $body = (string) json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => $session]]);
        $time = $this->shop->clock->now()->getTimestamp();
        $this->shop->outcomes->notified('stripe', new Notification(['stripe-signature' => "t={$time},v1=" . hash_hmac('sha256', "{$time}.{$body}", self::STRIPE_SECRET)], $body));

        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['processing', 'pi_1'], [$order->status, $order->transactionId]);
    }

    public function testAnEndedPaymentHearingAgainThatItEndedChangesNothing(): void
    {
        $placed = $this->place('paypal');
        $this->shop->payPalApi->captureStatus = 'DECLINED';
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);
        $notes = $this->shop->orders->order($placed->number)->notes;

        $this->shop->outcomes->notified('paypal', $this->payPalCapture('DECLINED', 'PAYPAL-1', '8.00'));

        $order = $this->shop->orders->order($placed->number);
        self::assertCount(\count($notes), $order->notes);
        self::assertNull($order->attention);
    }

    private function place(string $gateway, int $quantity = 1): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20, quantity: $quantity))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: $gateway));
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function payPal(string $type, array $resource): Notification
    {
        return PayPalNotifications::signed($this->shop, $type, $resource);
    }

    private function payPalCapture(string $status, string $order, string $amount): Notification
    {
        return $this->payPal('PAYMENT.CAPTURE.' . $status, [
            'id' => "CAPTURE-{$order}",
            'status' => $status,
            'amount' => ['value' => $amount, 'currency_code' => 'EUR'],
            'supplementary_data' => ['related_ids' => ['order_id' => $order]],
        ]);
    }
}
