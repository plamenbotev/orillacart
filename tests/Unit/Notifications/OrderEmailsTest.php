<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Notifications;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Notifications\Application\Dto\SendOrderEmail;
use OrillaCart\Notifications\Application\SendOrderEmails;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettings;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class OrderEmailsTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true, receiptNote: 'IBAN BG00 0000 0000'));
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 's']));
    }

    public function testAnOfflineOrderIsReceivedThenConfirmedWhenTheMoneyArrives(): void
    {
        $order = $this->place('bank_transfer');

        self::assertSame([
            "ada@example.com: [Lamp Shop] We received your order #{$order->number}",
            "owner@shop.test: [Lamp Shop] New order #{$order->number} from Ada Lovelace",
        ], $this->shop->mail->summary());
        $received = $this->shop->mail->emails[0];
        self::assertStringContainsString('IBAN BG00 0000 0000', $received->html, 'the bank details are in the email');
        self::assertStringContainsString('8.00 EUR', $received->html);
        self::assertStringContainsString($order->receiptUrl, $received->html);
        self::assertSame(['Lamp Shop', 'owner@shop.test', null], [$received->fromName, $received->fromAddress, $received->replyTo]);
        self::assertSame('ada@example.com', $this->shop->mail->emails[1]->replyTo, 'the shop answers the customer directly');

        $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('processing'), 1);
        $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('completed'), 1);

        self::assertSame([
            "ada@example.com: [Lamp Shop] Your order #{$order->number} is confirmed",
            "ada@example.com: [Lamp Shop] Your order #{$order->number} is complete",
        ], \array_slice($this->shop->mail->summary(), 2));
    }

    public function testAPayPalOrderIsConfirmedOnceWhenPaid(): void
    {
        $order = $this->place('paypal');
        self::assertSame([], $this->shop->mail->summary(), 'nothing while the customer is at PayPal');

        $this->shop->outcomes->returned('paypal', ['token' => 'PAYPAL-1'], false);

        self::assertSame([
            "ada@example.com: [Lamp Shop] Your order #{$order->number} is confirmed",
            "owner@shop.test: [Lamp Shop] New order #{$order->number} from Ada Lovelace",
        ], $this->shop->mail->summary());
    }

    public function testGivingUpAtPayPalSendsNothingButTheShopCancellingDoes(): void
    {
        $this->place('paypal');
        $this->shop->outcomes->returned('paypal', ['token' => 'PAYPAL-1'], true);
        self::assertSame([], $this->shop->mail->summary());

        $order = $this->place('bank_transfer');
        $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('cancelled', note: 'Out of stock.'), 1);
        self::assertContains("ada@example.com: [Lamp Shop] Your order #{$order->number} was cancelled", $this->shop->mail->summary());
    }

    public function testNotesForTheCustomerAreEmailedOthersAreNot(): void
    {
        $order = $this->place('bank_transfer');

        $this->shop->orders->addNote($order->number, new AddOrderNote('Called the courier.'), 1);
        $this->shop->orders->addNote($order->number, new AddOrderNote("Your parcel leaves tomorrow.\nThanks!", forCustomer: true), 1);

        self::assertCount(3, $this->shop->mail->emails);
        self::assertStringContainsString("Your parcel leaves tomorrow.\nThanks!", $this->shop->mail->emails[2]->html);
        self::assertSame(['Order placed.', 'Status changed from pending payment to on hold.', 'Called the courier.', "Your parcel leaves tomorrow.\nThanks!"], array_column($this->shop->orders->order($order->number)->notes, 'text'));
        self::assertSame(["Your parcel leaves tomorrow.\nThanks!"], array_column($this->shop->orders->byKey($order->key)->notes, 'text'), 'the customer sees only notes for them');
    }

    public function testTurnedOffEmailsAreNotSentUnlessTheShopResendsThem(): void
    {
        $this->shop->emailSettings->save(new EmailSettings('Lamps & Co', 'shop@lamps.test', 'orders@lamps.test', [EmailKind::OrderReceived]));

        $order = $this->place('bank_transfer');
        self::assertSame(["orders@lamps.test: [Lamp Shop] New order #{$order->number} from Ada Lovelace"], $this->shop->mail->summary());
        self::assertSame(['Lamps & Co', 'shop@lamps.test'], [$this->shop->mail->emails[0]->fromName, $this->shop->mail->emails[0]->fromAddress]);

        self::assertTrue($this->shop->emails->resend($order->number, new SendOrderEmail('order_received')));
        self::assertStringContainsString('We received your order', $this->shop->mail->summary()[1]);
    }

    public function testAMailThatCannotBeSentIsTriedAgainLaterAndTheOrderGoesOn(): void
    {
        $this->shop->mail->accepts = false;

        $order = $this->place('bank_transfer');

        self::assertSame('on_hold', $order->status);
        self::assertSame([], $this->shop->mail->emails);
        $waiting = $this->shop->jobs->jobs->waiting(SendOrderEmails::TYPE);
        self::assertSame([0, 2], [$waiting->due, $waiting->later], 'both wait a minute');

        $this->shop->mail->accepts = true;
        $this->shop->clock->sleep(60);
        $this->shop->jobs->runAll();
        self::assertCount(2, $this->shop->mail->emails);
    }

    public function testAJobRunAgainDoesNotSendItsMailTwice(): void
    {
        $order = $this->place('bank_transfer');
        $sent = \count($this->shop->mail->emails);
        $job = new QueuedJob('142', 'mail', SendOrderEmails::TYPE, null, ['order' => $order->number, 'kind' => 'order_received'], 1, 1);
        $handler = $this->shop->jobs->get(SendOrderEmails::TYPE);

        // Its worker stopped after the mail went out, so another worker claims it again.
        $handler->handle([$job]);
        $handler->handle([$job]);

        self::assertCount($sent + 1, $this->shop->mail->emails);
    }

    public function testAnEmailThatBreaksStaysWithItsJobAndTheOthersGoOutOnce(): void
    {
        $order = $this->place('bank_transfer');
        $sent = \count($this->shop->mail->emails);
        $this->shop->mail->breaksFor = 'owner@shop.test';
        $customer = new QueuedJob('101', 'mail', SendOrderEmails::TYPE, null, ['order' => $order->number, 'kind' => 'order_received'], 1, 1);
        $shop = new QueuedJob('102', 'mail', SendOrderEmails::TYPE, null, ['order' => $order->number, 'kind' => 'new_order'], 1, 1);

        $outcomes = $this->shop->jobs->get(SendOrderEmails::TYPE)->handle([$customer, $shop]);

        self::assertCount(1, $outcomes, 'only the broken one is tried again');
        self::assertTrue(($outcomes[$shop->id] ?? null)?->isRetry());
        self::assertCount($sent + 1, $this->shop->mail->emails);
    }

    public function testAMailRefusedEveryTimeIsFailedForTheShopToSee(): void
    {
        $this->shop->mail->accepts = false;
        $order = $this->place('bank_transfer');

        for ($attempt = 1; $attempt < 5; ++$attempt) {
            $this->shop->clock->sleep(60 * 4 ** ($attempt - 1));
            $this->shop->jobs->runAll();
        }

        $failed = $this->shop->jobs->jobs->failed(SendOrderEmails::TYPE);
        self::assertCount(2, $failed);
        self::assertSame(["Order #{$order->number}: order received email", 5], [$failed[1]->about, $failed[1]->attempts]);
        self::assertStringContainsString('the mail system refused it', $failed[0]->error);
    }

    private function place(string $payment): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: $payment));
    }
}
