<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Payment;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Application\Dto\RestockItems;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class RefundsTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'Lamp');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->shop->stock->setLevels(StockItemRef::product(20), new SetStockLevels([new SetStockLevel($room, 10)]));
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1']));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testPayPalRefundsPartOfAnOrderThenTheRestAndTheItemsGoBack(): void
    {
        $order = $this->paidWithPayPal(3);
        self::assertSame('PayPal', $this->shop->refunds->forOrder($order)->provider);
        self::assertSame(7, $this->available());

        $view = $this->shop->refunds->refund($order, new MakeRefund('8', [new RefundLine(0, 1)], restock: true, reason: 'Arrived broken.'), 7);

        self::assertSame([['capture' => 'CAPTURE-PAYPAL-1', 'amount' => '8.00', 'currency' => 'EUR']], array_values($this->shop->payPalApi->refunds));
        self::assertSame(['16', 'done', 'REFUND-1'], [$view->refundable, $view->refunds[0]->status, $view->refunds[0]->providerReference]);
        $placed = $this->shop->orders->order($order);
        self::assertSame(['processing', '8', 1], [$placed->status, $placed->refundedTotal, $placed->lines[0]->refundedQuantity]);
        self::assertSame(8, $this->available(), 'the refunded lamp is back in stock');
        self::assertStringContainsString('Refunded 8 EUR through PayPal (refund REFUND-1). The items went back in stock. Arrived broken.', $placed->notes[\count($placed->notes) - 1]->text);
        self::assertStringContainsString('We refunded part of your order', $this->lastMail());

        $this->shop->refunds->refund($order, new MakeRefund('16', [new RefundLine(0, 2)]), 7);

        self::assertSame('refunded', $this->shop->orders->order($order)->status);
        self::assertSame(8, $this->available(), 'refunded without restocking: those stay sold');
        self::assertStringContainsString('Your order #' . $order . ' was refunded', $this->lastMail());
        $this->expectException(OrderRuleViolation::class);
        $this->shop->refunds->refund($order, new MakeRefund('1'), 7);
    }

    public function testARefundPayPalRefusesChangesNothing(): void
    {
        $order = $this->paidWithPayPal(1);
        $this->shop->payPalApi->refuses = 'The payment is too old to refund through PayPal.';

        try {
            $this->shop->refunds->refund($order, new MakeRefund('8'), 7);
            self::fail('refused');
        } catch (PaymentRuleViolation $e) {
            self::assertSame(['orillacart_refund_refused', 'PayPal refused the refund: The payment is too old to refund through PayPal.'], [$e->errorCode, $e->getMessage()]);
        }

        self::assertSame(['processing', '0'], [$this->shop->orders->order($order)->status, $this->shop->orders->order($order)->refundedTotal]);
        self::assertSame('failed', $this->shop->refunds->forOrder($order)->refunds[0]->status);
    }

    public function testALostAnswerLeavesTheRefundPendingAndTryingAgainNeverRefundsTwice(): void
    {
        $order = $this->paidWithPayPal(2);
        $this->shop->payPalApi->answerLost = true;

        try {
            $this->shop->refunds->refund($order, new MakeRefund('8'), 7);
            self::fail('no answer');
        } catch (PaymentRuleViolation $e) {
            self::assertSame('orillacart_refund_unanswered', $e->errorCode);
        }
        $pending = $this->shop->refunds->forOrder($order)->refunds[0];
        self::assertSame(['pending', '0'], [$pending->status, $this->shop->orders->order($order)->refundedTotal]);
        try {
            $this->shop->refunds->refund($order, new MakeRefund('4'), 7);
            self::fail('another refund while one waits');
        } catch (PaymentRuleViolation $e) {
            self::assertSame('orillacart_refund_pending', $e->errorCode);
        }

        $this->shop->payPalApi->answerLost = false;
        $view = $this->shop->refunds->retry($pending->id, 7);

        self::assertCount(1, $this->shop->payPalApi->refunds, 'the same request id: PayPal refunded once');
        self::assertSame(['done', '8'], [$view->refunds[0]->status, $this->shop->orders->order($order)->refundedTotal]);
    }

    public function testARefundWhoseGatewayBreaksWaitsWithTheErrorToBeTriedAgain(): void
    {
        $order = $this->paidWithPayPal(2);
        $this->shop->payPalApi->breaks = new \TypeError('A bug in the gateway.');

        try {
            $this->shop->refunds->refund($order, new MakeRefund('8'), 7);
            self::fail('the gateway broke');
        } catch (PaymentRuleViolation $e) {
            self::assertSame('orillacart_refund_unanswered', $e->errorCode);
        }
        $pending = $this->shop->refunds->forOrder($order)->refunds[0];
        self::assertSame('pending', $pending->status);
        self::assertStringContainsString('A bug in the gateway.', (string) $pending->error);

        $this->shop->payPalApi->breaks = null;
        self::assertSame('done', $this->shop->refunds->retry($pending->id, 7)->refunds[0]->status);
    }

    public function testARefundPayPalFinishesLaterTouchesTheOrderOnlyOnceItIsDone(): void
    {
        $order = $this->paidWithPayPal(2);
        $this->shop->payPalApi->refundStatus = 'PENDING';
        $sent = \count($this->shop->mail->emails);

        $view = $this->shop->refunds->refund($order, new MakeRefund('8', [new RefundLine(0, 1)], restock: true), 7);

        self::assertSame('accepted', $view->refunds[0]->status);
        self::assertSame('0', $this->shop->orders->order($order)->refundedTotal, 'nothing went back yet');
        self::assertCount($sent, $this->shop->mail->emails, 'nor is the customer told');
        try {
            $this->shop->refunds->refund($order, new MakeRefund('4'), 7);
            self::fail('one refund at a time');
        } catch (PaymentRuleViolation $e) {
            self::assertSame('orillacart_refund_pending', $e->errorCode);
        }

        self::assertSame(0, $this->shop->refunds->reconcile(), 'still on its way');
        $this->shop->payPalApi->refundStatus = 'COMPLETED';
        self::assertSame(1, $this->shop->refunds->reconcile());

        self::assertSame('done', $this->shop->refunds->forOrder($order)->refunds[0]->status);
        self::assertSame('8', $this->shop->orders->order($order)->refundedTotal);
        self::assertCount($sent + 1, $this->shop->mail->emails, 'the customer hears of it now');
    }

    public function testARefundPayPalGivesUpOnFlagsTheOrderForTheShop(): void
    {
        $order = $this->paidWithPayPal(2);
        $this->shop->payPalApi->refundStatus = 'PENDING';
        $this->shop->refunds->refund($order, new MakeRefund('8'), 7);

        $this->shop->payPalApi->refundStatus = 'FAILED';
        $this->shop->refunds->reconcile();

        $view = $this->shop->orders->order($order);
        self::assertSame('failed', $this->shop->refunds->forOrder($order)->refunds[0]->status);
        self::assertSame(['processing', '0'], [$view->status, $view->refundedTotal]);
        self::assertStringContainsString('did not go through', (string) $view->attention);
        self::assertStringContainsString('needs your attention', $this->shop->mail->summary()[\count($this->shop->mail->emails) - 1]);

        $handled = $this->shop->orders->attended($order, 7);
        self::assertNull($handled->attention);
        self::assertStringStartsWith('Marked as handled', $handled->notes[\count($handled->notes) - 1]->text);
    }

    public function testPayPalsWebhookCompletesARefundWhoseAnswerWasLost(): void
    {
        $order = $this->paidWithPayPal(2);
        $this->shop->payPalApi->answerLost = true;
        try {
            $this->shop->refunds->refund($order, new MakeRefund('8'), 7);
        } catch (PaymentRuleViolation) {
        }

        $this->shop->outcomes->notified('paypal', $this->refunded('REFUND-1', '8.00'));
        $this->shop->outcomes->notified('paypal', $this->refunded('REFUND-1', '8.00'));

        $view = $this->shop->refunds->forOrder($order);
        self::assertCount(1, $view->refunds, 'the webhook is about the refund the shop sent');
        self::assertSame(['done', 'REFUND-1', '8'], [$view->refunds[0]->status, $view->refunds[0]->providerReference, $view->refundable]);
    }

    public function testARefundMadeInPayPalsDashboardIsRecordedOnce(): void
    {
        $order = $this->paidWithPayPal(2);

        $this->shop->outcomes->notified('paypal', $this->refunded('DASH-1', '16.00'));
        $this->shop->outcomes->notified('paypal', $this->refunded('DASH-1', '16.00'));

        $view = $this->shop->refunds->forOrder($order);
        self::assertSame([['external', 'done', 'DASH-1']], array_map(static fn($refund): array => [$refund->method, $refund->status, $refund->providerReference], $view->refunds));
        self::assertSame('refunded', $this->shop->orders->order($order)->status);
        self::assertSame([], $this->shop->payPalApi->refunds, 'nothing sent to PayPal');
        self::assertStringContainsString('if the items came back, put them back in stock', (string) $this->shop->orders->order($order)->attention, 'the shop is asked about the items');
    }

    public function testItemsOfAnOrderRefundedByAmountGoBackInStockWhenTheyComeBack(): void
    {
        $order = $this->paidWithPayPal(3);
        $this->shop->refunds->refund($order, new MakeRefund('8', [new RefundLine(0, 1)]), 7);
        $this->shop->refunds->refund($order, new MakeRefund('16'), 7);
        self::assertSame(['refunded', 2], [$this->shop->orders->order($order)->status, $this->shop->orders->order($order)->lines[0]->restockable]);
        self::assertSame(7, $this->available(), 'refunded by amount: the stock waits for the items');

        $view = $this->shop->orders->restock($order, new RestockItems([new RefundLine(0, 2)]), 7);

        self::assertSame(9, $this->available());
        self::assertSame([3, 0], [$view->lines[0]->refundedQuantity, $view->lines[0]->restockable]);
        self::assertSame('2 items came back and went back in stock.', $view->notes[\count($view->notes) - 1]->text);
        $this->expectException(OrderRuleViolation::class);
        $this->shop->orders->restock($order, new RestockItems([new RefundLine(0, 1)]), 7);
    }

    public function testAnOfflinePaymentIsRefundedAsARecordOfMoneySentOutside(): void
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', self::address(), payment: 'bank_transfer'));
        try {
            $this->shop->refunds->refund($placed->number, new MakeRefund('8'), 7);
            self::fail('not paid yet');
        } catch (OrderRuleViolation $e) {
            self::assertSame('orillacart_order_not_refundable', $e->errorCode);
        }
        $this->shop->orders->markPaidByShop($placed->number, '', 7);
        self::assertNull($this->shop->refunds->forOrder($placed->number)->provider);

        try {
            $this->shop->refunds->refund($placed->number, new MakeRefund('9'), 7);
            self::fail('more than was paid');
        } catch (OrderRuleViolation $e) {
            self::assertSame('orillacart_invalid_refund_amount', $e->errorCode);
        }
        $view = $this->shop->refunds->refund($placed->number, new MakeRefund('3', reason: 'Goodwill.'), 7);

        self::assertSame(['manual', 'done', '5'], [$view->refunds[0]->method, $view->refunds[0]->status, $view->refundable]);
        self::assertStringContainsString('Refunded 3 EUR outside the shop. Goodwill.', $this->shop->orders->order($placed->number)->notes[\count($this->shop->orders->order($placed->number)->notes) - 1]->text);
    }

    private function paidWithPayPal(int $quantity): int
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20, quantity: $quantity))->newToken);
        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', self::address(), payment: 'paypal'));
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);
        self::assertSame('processing', $this->shop->orders->order($placed->number)->status);

        return $placed->number;
    }

    private function refunded(string $refundId, string $amount): Notification
    {
        return new Notification(['paypal-transmission-id' => 'T-1'], (string) json_encode(['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => [
            'id' => $refundId,
            'status' => 'COMPLETED',
            'amount' => ['value' => $amount, 'currency_code' => 'EUR'],
            'links' => [['rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/CAPTURE-PAYPAL-1']],
        ]]));
    }

    private function available(): ?int
    {
        return $this->shop->stock->available([StockItemRef::product(20)]);
    }

    private function lastMail(): string
    {
        $emails = $this->shop->mail->emails;

        return $emails === [] ? '' : $emails[\count($emails) - 1]->subject;
    }

    private static function address(): AddressData
    {
        return new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000');
    }
}
