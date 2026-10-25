<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Returns;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Returns\Application\Dto\AnswerReturn;
use OrillaCart\Returns\Application\Dto\DeclareWithdrawal;
use OrillaCart\Returns\Application\Dto\FindWithdrawableOrder;
use OrillaCart\Returns\Application\Dto\RefundReturn;
use OrillaCart\Returns\Application\Dto\RequestReturn;
use OrillaCart\Returns\Application\Dto\ReturnedItemView;
use OrillaCart\Returns\Application\Dto\ReturnItem;
use OrillaCart\Returns\Domain\ReturnRuleViolation;
use OrillaCart\Returns\Domain\WithdrawalOrderNotFound;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * A customer sends items back within the shop's return window; the shop approves, receives and refunds them.
 */
final class ReturnsTest extends TestCase
{
    private TestShop $shop;
    private int $order;
    private string $receipt;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG', returnDays: 30));
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '2', stockMode: 'always_available'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $rate = $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Anywhere', '5')]))->rates[0]->id;
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 2))->newToken);
        $this->order = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$rate}", payment: 'bank_transfer'))->number;
        $this->shop->orders->markPaid($this->order, null);
        $this->receipt = $this->shop->receipts->issue($this->order);
    }

    public function testAConsumerWithdrawsBeforeDeliveryAndIsToldAtOnceWhatTheyDeclaredAndWhen(): void
    {
        $found = $this->shop->returns->withdrawable(new FindWithdrawableOrder($this->order, 'ADA@example.com'));
        self::assertSame([['line' => 0, 'name' => 'Lamp', 'quantity' => 2]], $found->lines, 'not shipped yet, and still withdrawable');
        self::assertSame('Ada Lovelace', $found->name);

        $withdrawn = $this->shop->returns->withdraw(new DeclareWithdrawal($this->order, 'ada@example.com', 'Ada Lovelace', 'ada.other@example.com', [new ReturnItem(0, 1)]));

        self::assertSame([['name' => 'Lamp', 'quantity' => 1]], $withdrawn->items);
        self::assertSame('2026-10-17T12:00:00+00:00', $withdrawn->declaredAt);
        $acknowledged = $this->shop->mail->ofKind('withdrawal_received');
        self::assertSame(['ada.other@example.com', 'ada@example.com'], array_map(static fn(Email $email): string => $email->to, $acknowledged), 'to the address given, and the order\'s');
        self::assertStringContainsString('We received your withdrawal', $acknowledged[0]->html ?? '');
        self::assertStringContainsString('1 × Lamp', $acknowledged[0]->html ?? '');
        self::assertSame('withdrawal', $this->shop->returns->forOrder($this->order)->returns[0]->reason ?? null);
        self::assertSame([['line' => 0, 'name' => 'Lamp', 'quantity' => 1]], $this->shop->returns->withdrawable(new FindWithdrawableOrder($this->order, 'ada@example.com'))->lines, 'the other one is left');
    }

    public function testAnOrderIsFoundOnlyWithItsOwnEmail(): void
    {
        $this->expectException(WithdrawalOrderNotFound::class);
        $this->shop->returns->withdrawable(new FindWithdrawableOrder($this->order, 'eve@example.com'));
    }

    public function testAnOrderIsReturnedOnlyOnceItLeftAndWithinTheWindow(): void
    {
        self::assertFalse($this->shop->returns->forReceipt($this->receipt)->accepting, 'it has not left');

        $this->shop->orders->changeStatus($this->order, new ChangeOrderStatus('shipped'), 7);
        $returns = $this->shop->returns->forReceipt($this->receipt);
        self::assertSame([true, '2026-11-16'], [$returns->accepting, $returns->until]);
        self::assertSame([['Lamp', 2]], array_map(static fn(ReturnedItemView $item): array => [$item->name, $item->quantity], $returns->returnable));

        $this->shop->clock->modify('+31 days');
        self::assertFalse($this->shop->returns->forReceipt($this->receipt)->accepting);
        $this->expectException(ReturnRuleViolation::class);
        $this->shop->returns->request($this->receipt, new RequestReturn([new ReturnItem(0, 1)], 'damaged'));
    }

    public function testTheShopHearsApprovesReceivesAndRefundsTheItems(): void
    {
        $this->shop->orders->changeStatus($this->order, new ChangeOrderStatus('shipped'), 7);

        $return = $this->shop->returns->request($this->receipt, new RequestReturn([new ReturnItem(0, 1)], 'damaged', 'The shade is cracked.'));
        self::assertSame(['requested', '30'], [$return->status, $return->amount]);
        $order = $this->shop->orders->order($this->order);
        self::assertSame('The customer asks to return 1 × Lamp: it arrived damaged. "The shade is cracked."', $order->attention);
        self::assertSame([['Lamp', 1]], array_map(static fn(ReturnedItemView $item): array => [$item->name, $item->quantity], $this->shop->returns->forOrder($this->order)->returnable), 'one is asked for');
        try {
            $this->shop->returns->request($this->receipt, new RequestReturn([new ReturnItem(0, 2)], 'other'));
            self::fail('only one is left');
        } catch (ReturnRuleViolation $e) {
            self::assertSame(['orillacart_invalid_return_items', '1 of "Lamp" can go back.'], [$e->errorCode, $e->getMessage()]);
        }

        $this->shop->returns->approve($return->id, new AnswerReturn('Send it to 1 Shop St, Sofia.'), 7);
        $mails = $this->shop->mail->summary();
        self::assertStringContainsString('Your return of 1 × Lamp is approved. Send it to 1 Shop St, Sofia.', $this->shop->mail->emails[\count($mails) - 1]->html, 'the customer reads it as a note');

        try {
            $this->shop->returns->refund($return->id, new RefundReturn(), 7);
            self::fail('it has not come back');
        } catch (ReturnRuleViolation $e) {
            self::assertSame('orillacart_return_not_refundable', $e->errorCode);
        }
        $this->shop->returns->receive($return->id, 7);
        $refunded = $this->shop->returns->refund($return->id, new RefundReturn(outside: true), 7);

        self::assertSame('refunded', $refunded->status);
        $order = $this->shop->orders->order($this->order);
        self::assertSame(['30', 1, 'shipped'], [$order->refundedTotal, $order->lines[0]->refundedQuantity, $order->status]);
        self::assertSame([['Lamp', 1]], array_map(static fn(ReturnedItemView $item): array => [$item->name, $item->quantity], $this->shop->returns->forOrder($this->order)->returnable), 'the other may still go back');
    }

    public function testAReturnIsRefundedOnceTheProviderSentTheMoney(): void
    {
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1']));
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: $this->shop->orders->order($this->order)->shippingOption, payment: 'paypal'));
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('shipped'), 7);
        $return = $this->shop->returns->request($this->shop->receipts->issue($placed->number), new RequestReturn([new ReturnItem(0, 1)], 'damaged'));
        $this->shop->returns->receive($return->id, 7);
        $this->shop->payPalApi->refundStatus = 'PENDING';

        self::assertSame('refunding', $this->shop->returns->refund($return->id, new RefundReturn(restock: false), 7)->status, 'PayPal sends the money later');
        self::assertSame([], $this->shop->returns->forOrder($placed->number)->returnable, 'its items are still held');

        $this->shop->payPalApi->refundStatus = 'COMPLETED';
        $this->shop->refunds->reconcile();
        self::assertSame('refunded', $this->shop->returns->forOrder($placed->number)->returns[0]->status);
    }

    public function testAReturnWhoseRefundFailedIsRefundedAgain(): void
    {
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1']));
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: $this->shop->orders->order($this->order)->shippingOption, payment: 'paypal'));
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('shipped'), 7);
        $return = $this->shop->returns->request($this->shop->receipts->issue($placed->number), new RequestReturn([new ReturnItem(0, 1)], 'other'));
        $this->shop->returns->receive($return->id, 7);
        $this->shop->payPalApi->refundStatus = 'PENDING';
        $this->shop->returns->refund($return->id, new RefundReturn(), 7);

        $this->shop->payPalApi->refundStatus = 'FAILED';
        $this->shop->refunds->reconcile();

        self::assertSame('received', $this->shop->returns->forOrder($placed->number)->returns[0]->status, 'the money did not go: the shop refunds it again');
    }

    public function testAReturnWhoseRefundWentUnansweredIsRefundedOnceItIsTriedAgain(): void
    {
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1']));
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: $this->shop->orders->order($this->order)->shippingOption, payment: 'paypal'));
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('shipped'), 7);
        $return = $this->shop->returns->request($this->shop->receipts->issue($placed->number), new RequestReturn([new ReturnItem(0, 1)], 'damaged'));
        $this->shop->returns->receive($return->id, 7);
        $this->shop->payPalApi->answerLost = true;
        try {
            $this->shop->returns->refund($return->id, new RefundReturn(), 7);
            self::fail('PayPal did not answer');
        } catch (PaymentRuleViolation $e) {
            self::assertSame('orillacart_refund_unanswered', $e->errorCode);
        }
        self::assertSame('refunding', $this->shop->returns->forOrder($placed->number)->returns[0]->status, 'it follows the refund that waits');

        $this->shop->payPalApi->answerLost = false;
        $this->shop->refunds->retry($this->shop->refunds->forOrder($placed->number)->refunds[0]->id, 7);
        self::assertSame('refunded', $this->shop->returns->forOrder($placed->number)->returns[0]->status);
    }

    public function testAnOrderNotPaidHasNothingToGiveBack(): void
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: $this->shop->orders->order($this->order)->shippingOption, payment: 'bank_transfer'));
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('processing'), 7);
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('shipped'), 7);

        self::assertFalse($this->shop->returns->forReceipt($this->shop->receipts->issue($placed->number))->accepting);
    }

    public function testARefusalSaysWhyAndLeavesTheItemsReturnable(): void
    {
        $this->shop->orders->changeStatus($this->order, new ChangeOrderStatus('shipped'), 7);
        $return = $this->shop->returns->request($this->receipt, new RequestReturn([new ReturnItem(0, 2)], 'no_longer_needed'));

        try {
            $this->shop->returns->refuse($return->id, new AnswerReturn(''), 7);
            self::fail('no reason');
        } catch (ReturnRuleViolation $e) {
            self::assertSame('orillacart_invalid_return_answer', $e->errorCode);
        }
        self::assertSame('refused', $this->shop->returns->refuse($return->id, new AnswerReturn('Opened items do not go back.'), 7)->status);
        self::assertSame(2, $this->shop->returns->forOrder($this->order)->returnable[0]->quantity);
    }

    public function testAShopThatTakesNoReturnsOffersNone(): void
    {
        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG'));
        $this->shop->orders->changeStatus($this->order, new ChangeOrderStatus('shipped'), 7);

        self::assertSame([false, null], [$this->shop->returns->forReceipt($this->receipt)->accepting, $this->shop->returns->forReceipt($this->receipt)->until]);
    }
}
