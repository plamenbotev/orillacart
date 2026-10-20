<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Notifications;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Notifications\Application\Dto\SendOrderEmail;
use OrillaCart\Notifications\Application\PaymentReminders;
use OrillaCart\Notifications\Application\SendOrderEmails;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettings;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Ordering\Application\Dto\RefundOrder;
use OrillaCart\Ordering\Application\ReceiptLinkExpired;
use OrillaCart\Ordering\Domain\OrderNotFound;
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
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 's', 'webhook_id' => 'WH-1']));
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

    public function testTheCustomerIsWrittenInTheLanguageTheyOrderedInAndTheShopInItsOwn(): void
    {
        $this->shop->languages->locale = 'de_DE';

        $order = $this->place('bank_transfer');

        self::assertSame('de_DE', $this->shop->orders->order($order->number)->locale);
        self::assertSame(['de_DE', null], $this->shop->languages->switched, 'the received email, then the shop\'s new order email');
        self::assertStringContainsString('<html lang="de-DE">', $this->shop->mail->emails[0]->html);
        self::assertStringContainsString('<html lang="en-US">', $this->shop->mail->emails[1]->html);
    }

    public function testASubjectReadsAsWrittenAndEveryEmailHasItsPlainText(): void
    {
        $this->shop->site->name = 'Lamps & "Lights"';

        $order = $this->place('bank_transfer');

        $received = $this->shop->mail->emails[0];
        self::assertSame("[Lamps & \"Lights\"] We received your order #{$order->number}", $received->subject);
        self::assertStringContainsString('Thank you for your order', $received->text);
        self::assertStringContainsString("Pay now ({$order->receiptUrl}#pay)", $received->text);
        self::assertStringNotContainsString('<', $received->text);
    }

    public function testAReceiptLinkWorksForNinetyDaysThenANewOneGoesToTheOrdersEmail(): void
    {
        $order = $this->place('bank_transfer');
        $link = $this->shop->receipts->issue($order->number);
        self::assertSame($order->number, $this->shop->orders->byReceipt($link)->number);
        foreach ([$order->number . '-1-' . str_repeat('0', 32), substr($link, 0, -1) . 'f', ($order->number + 1) . substr($link, \strlen((string) $order->number))] as $forged) {
            try {
                $this->shop->orders->byReceipt($forged);
                self::fail("{$forged} is not a link the shop made");
            } catch (OrderNotFound) {
                // Not found, rather than expired: there is no new link to send for it.
            }
        }

        $this->shop->clock->modify('+90 days');
        try {
            $this->shop->orders->byReceipt($link);
            self::fail('expired');
        } catch (ReceiptLinkExpired) {
        }
        $sent = \count($this->shop->mail->emails);
        $this->shop->emails->receiptLink($order->number . '-1-' . str_repeat('0', 32));
        self::assertCount($sent, $this->shop->mail->emails, 'nothing for a link the shop never made');

        $this->shop->emails->receiptLink($link);

        $mail = $this->shop->mail->emails[$sent];
        self::assertSame(['ada@example.com', "[Lamp Shop] The link to your order #{$order->number}"], [$mail->to, $mail->subject]);
        self::assertStringContainsString($this->shop->receipts->issue($order->number), $mail->html, 'a link made today');
    }

    public function testAGuestFindsTheirOrderByItsNumberAndEmailInTheirInbox(): void
    {
        $order = $this->place('bank_transfer');
        $sent = \count($this->shop->mail->emails);

        $this->shop->emails->findOrder($order->number, 'grace@example.com');
        $this->shop->emails->findOrder($order->number + 1, 'ada@example.com');
        self::assertCount($sent, $this->shop->mail->emails, 'nothing for an email or number that does not match');

        $this->shop->emails->findOrder($order->number, ' ADA@example.com');
        $mail = $this->shop->mail->emails[$sent];
        self::assertSame(['ada@example.com', "[Lamp Shop] The link to your order #{$order->number}"], [$mail->to, $mail->subject]);
        self::assertStringContainsString($this->shop->receipts->issue($order->number), $mail->html);
    }

    public function testAPayPalOrderIsConfirmedOnceWhenPaid(): void
    {
        $order = $this->place('paypal');
        self::assertSame([], $this->shop->mail->summary(), 'nothing while the customer is at PayPal');

        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);

        self::assertSame([
            "ada@example.com: [Lamp Shop] Your order #{$order->number} is confirmed",
            "owner@shop.test: [Lamp Shop] New order #{$order->number} from Ada Lovelace",
        ], $this->shop->mail->summary());
    }

    public function testPayPalsWebhookArrivingWhileTheCustomerComesBackConfirmsTheOrderOnce(): void
    {
        $order = $this->place('paypal');
        $this->shop->payPalApi->whileCapturing = fn() => $this->shop->outcomes->notified('paypal', new Notification(['paypal-transmission-id' => 'T-1'], (string) json_encode([
            'id' => 'WH-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'CAPTURE-PAYPAL-1', 'status' => 'COMPLETED', 'amount' => ['value' => '8.00', 'currency_code' => 'EUR'], 'supplementary_data' => ['related_ids' => ['order_id' => 'PAYPAL-1']]],
        ])));

        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);

        self::assertSame('processing', $this->shop->orders->order($order->number)->status);
        self::assertSame([
            "ada@example.com: [Lamp Shop] Your order #{$order->number} is confirmed",
            "owner@shop.test: [Lamp Shop] New order #{$order->number} from Ada Lovelace",
        ], $this->shop->mail->summary());
        self::assertSame(['Order placed.', 'Status changed from pending payment to processing. Paid (paypal, transaction CAPTURE-PAYPAL-1).'], array_column($this->shop->orders->order($order->number)->notes, 'text'), 'paid once, with no note of a second payment');
        self::assertSame(['PAYPAL-1'], $this->shop->payPalApi->captured);
    }

    public function testGivingUpAtPayPalSendsNothingButTheShopCancellingDoes(): void
    {
        $this->place('paypal');
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), true);
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
        self::assertSame(["Your parcel leaves tomorrow.\nThanks!"], array_column($this->shop->orders->byReceipt($this->shop->receipts->issue($order->number))->notes, 'text'), 'the customer sees only notes for them');
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

    public function testAnEmailJobKeepsNoNoteTextOrAddress(): void
    {
        $order = $this->place('bank_transfer');
        $this->shop->mail->accepts = false;
        $this->shop->orders->addNote($order->number, new AddOrderNote('Your door code 4321 works.', forCustomer: true), 1);
        for ($attempt = 1; $attempt < 5; ++$attempt) {
            $this->shop->clock->sleep(60 * 4 ** ($attempt - 1));
            $this->shop->jobs->runAll();
        }

        $failed = $this->shop->jobs->jobs->failed(SendOrderEmails::TYPE);
        self::assertCount(1, $failed);
        self::assertSame(['order' => $order->number, 'kind' => 'customer_note', 'note' => $this->shop->orders->order($order->number)->notes[2]->id], $failed[0]->payload);
        self::assertSame("The customer_note email of order {$order->number} to the customer was not sent: the mail system refused it.", $failed[0]->error);

        $this->shop->mail->accepts = true;
        $this->shop->jobs->jobs->retryFailed([$failed[0]->id]);
        $this->shop->jobs->runAll();
        $last = end($this->shop->mail->emails);
        self::assertNotFalse($last);
        self::assertStringContainsString('Your door code 4321 works.', $last->html, 'the note is read from the order');
    }

    public function testACustomerIsRemindedOnceToPayTheShopsDaysAfterOrdering(): void
    {
        $reminders = $this->reminders();
        $order = $this->place('bank_transfer');
        $paid = $this->place('bank_transfer');
        $this->shop->orders->markPaid($paid->number, null);
        $sent = \count($this->shop->mail->emails);

        $this->shop->clock->modify('+2 days');
        self::assertSame(0, $reminders->remind());

        $this->shop->clock->modify('+1 day');
        self::assertSame(1, $reminders->remind(), 'not the paid order');
        self::assertSame(["ada@example.com: [Lamp Shop] Your order #{$order->number} waits for your payment"], \array_slice($this->shop->mail->summary(), $sent));
        self::assertStringContainsString('IBAN BG00 0000 0000', $this->shop->mail->emails[$sent]->html, 'with the bank details again');

        $this->shop->clock->modify('+1 hour');
        self::assertSame(0, $reminders->remind(), 'once');
    }

    public function testRemindersFollowTheShopsDaysAndCanBeTurnedOff(): void
    {
        $reminders = $this->reminders();
        $this->place('bank_transfer');
        $this->shop->emailSettings->save(new EmailSettings(reminderDays: 5));

        $this->shop->clock->modify('+4 days');
        self::assertSame(0, $reminders->remind());

        $this->shop->emailSettings->save(new EmailSettings(disabled: [EmailKind::PaymentReminder], reminderDays: 5));
        $this->shop->clock->modify('+1 day');
        self::assertSame(0, $reminders->remind());
    }

    public function testAnOrderPaidBeforeItsReminderWentOutIsNotReminded(): void
    {
        $order = $this->place('bank_transfer');
        $this->shop->orders->markPaid($order->number, null);
        $sent = \count($this->shop->mail->emails);

        $this->shop->emails->send($order->number, EmailKind::PaymentReminder);

        self::assertCount($sent, $this->shop->mail->emails);
    }

    public function testOnlyMethodsPaidBeforeShippingAreReminded(): void
    {
        $offline = $this->shop->paymentOptions->paidOffline();

        self::assertContains('bank_transfer', $offline);
        self::assertContains('cheque', $offline);
        self::assertNotContains('cash_on_delivery', $offline, 'paid when it arrives');
        self::assertNotContains('paypal', $offline);
    }

    public function testTheShopHearsOfAFailedPaymentAndOfEveryRefund(): void
    {
        $failed = $this->place('paypal');
        $this->shop->orders->fail($failed->number, 'Declined.');

        self::assertSame([
            "ada@example.com: [Lamp Shop] The payment for order #{$failed->number} failed",
            "owner@shop.test: [Lamp Shop] The payment for order #{$failed->number} failed",
        ], $this->shop->mail->summary());

        $order = $this->place('bank_transfer');
        $this->shop->orders->markPaid($order->number, null);
        $sent = \count($this->shop->mail->emails);
        $this->shop->orders->refund($order->number, new RefundOrder('3', reason: 'Goodwill.'), 'by hand', 1, tellCustomer: false);

        self::assertSame(["owner@shop.test: [Lamp Shop] Order #{$order->number} was refunded"], \array_slice($this->shop->mail->summary(), $sent), 'not the customer, as the shop said');
        self::assertStringContainsString('3.00 EUR went back to the customer.', $this->shop->mail->emails[$sent]->html);
    }

    private function reminders(): PaymentReminders
    {
        return new PaymentReminders($this->shop->orders, $this->shop->paymentOptions, $this->shop->emailSettings, $this->shop->sentEmails, $this->shop->emails, $this->shop->clock);
    }

    private function place(string $payment): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: $payment));
    }
}
