<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Application\EmailConfirmations;
use OrillaCart\Checkout\Application\UnpaidOrders;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Shared\Http\Security\CallerKeys;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class UnpaidOrdersTest extends TestCase
{
    private TestShop $shop;
    private UnpaidOrders $unpaid;
    private int $room;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->room = $room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->stock->setLevels(StockItemRef::product(20), new SetStockLevels([new SetStockLevel($room, 5)]));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->unpaid = new UnpaidOrders($this->shop->orders, $this->shop->paymentOptions, $this->shop->settingsService, $this->shop->clock);
    }

    public function testAnOrderUnpaidTheShopsDaysIsCancelledAndItsStockSellsAgain(): void
    {
        $this->shop->settingsService->update(new UpdateSettings('EUR', null, 'kg', 'cm', null, null, unpaidHoldDays: 7));
        $late = $this->place();
        $paid = $this->place();
        $this->shop->orders->markPaid($paid->number, null);
        self::assertSame(3, $this->shop->stock->available([StockItemRef::product(20)]));

        $this->shop->clock->modify('+6 days');
        self::assertSame(0, $this->unpaid->expire());
        $this->shop->clock->modify('+1 day');
        self::assertSame(1, $this->unpaid->expire());

        self::assertSame('cancelled', $this->shop->orders->order($late->number)->status);
        self::assertSame('processing', $this->shop->orders->order($paid->number)->status);
        self::assertSame(4, $this->shop->stock->available([StockItemRef::product(20)]));
        self::assertContains("ada@example.com: [Lamp Shop] Your order #{$late->number} was cancelled", $this->shop->mail->summary());
    }

    public function testUnpaidOrdersAreCancelledAfterAWeekByDefault(): void
    {
        $order = $this->place();
        $this->shop->clock->modify('+6 days');
        self::assertSame(0, $this->unpaid->expire());

        $this->shop->clock->modify('+2 days');

        self::assertSame(1, $this->unpaid->expire());
        self::assertSame('cancelled', $this->shop->orders->order($order->number)->status);
    }

    public function testNothingIsCancelledWhileTheShopSetsNoDays(): void
    {
        $this->shop->settingsService->update(new UpdateSettings('EUR', null, 'kg', 'cm', null, null, unpaidHoldDays: null));
        $order = $this->place();
        $this->shop->clock->modify('+400 days');

        self::assertSame(0, $this->unpaid->expire());
        self::assertSame('on_hold', $this->shop->orders->order($order->number)->status);
    }

    public function testACustomerHoldsAFewOrdersWaitingForATransferAtOnce(): void
    {
        $this->stocked();
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $orders = [$this->place(customer: $ada), $this->place('ada+2@example.com', customer: $ada), $this->place('other@example.com', customer: $ada)];

        try {
            $this->place(customer: $ada);
            self::fail('three wait for the money');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame(['orillacart_too_many_unpaid_orders', 'Too many orders wait for a payment like this one. Choose another way to pay, or pay for the orders that wait first.'], [$e->errorCode, $e->getMessage()]);
        }
        self::assertSame('on_hold', $this->place('grace@example.com', customer: $this->shop->accounts->add('grace@example.com', 'Grace'))->status, 'another shopper orders');

        $this->shop->orders->markPaid($orders[0]->number, null);
        self::assertSame('on_hold', $this->place(customer: $ada)->status, 'one paid makes room');
    }

    public function testOrdersPlacedInSomeonesEmailNeverLockThemOut(): void
    {
        $this->stocked();
        // Someone else places transfers in Ada's email, and its variants, as a guest.
        foreach (['ada@example.com', 'Ada+a@example.com', 'ADA+b@example.com'] as $email) {
            $this->place($email, caller: 'ip:198.51.100.9');
        }

        self::assertSame('on_hold', $this->place(caller: 'ip:203.0.113.7')->status, 'Ada orders from her own address');
        self::assertSame('on_hold', $this->place(customer: $this->shop->accounts->add('ada@example.com', 'Ada'))->status, 'and from her account');
    }

    public function testAGuestHoldsAsManyOrdersWaitingForATransferFromOnePlaceAsTheShopSays(): void
    {
        $this->stocked();
        $this->shop->settingsService->update(new UpdateSettings('EUR', null, 'kg', 'cm', null, null, guestsAtUnpaidLimit: 'refuse'));
        $orders = [];
        for ($i = 0; $i < 3; ++$i) {
            $orders[] = $this->place("guest{$i}@example.com", caller: 'ip:198.51.100.9');
        }
        $refused = function (string $why): void {
            try {
                $this->place('guest4@example.com', caller: 'ip:198.51.100.9');
                self::fail($why);
            } catch (CheckoutRuleViolation $e) {
                self::assertSame('orillacart_too_many_unpaid_orders', $e->errorCode, $why);
            }
        };

        $refused('three wait for the money');
        $refused('and a try that was refused takes no room');
        self::assertSame('on_hold', $this->place('other@example.com', caller: 'ip:203.0.113.7')->status, 'another place orders');

        $this->shop->orders->markPaid($orders[0]->number, null);
        self::assertSame('on_hold', $this->place('guest4@example.com', caller: 'ip:198.51.100.9')->status, 'one paid makes room');
        $refused('for one');
        $this->shop->orders->cancel($orders[1]->number);
        self::assertSame('on_hold', $this->place('guest5@example.com', caller: 'ip:198.51.100.9')->status, 'and one cancelled');

        $this->shop->settingsService->update(new UpdateSettings('EUR', null, 'kg', 'cm', null, null, unpaidOrdersEach: 5, guestsAtUnpaidLimit: 'refuse'));
        self::assertSame('on_hold', $this->place('guest6@example.com', caller: 'ip:198.51.100.9')->status, 'the shop allows five');
        self::assertSame('on_hold', $this->place('guest7@example.com', caller: 'ip:198.51.100.9')->status);
        $refused('five wait');
        $this->shop->settingsService->update(new UpdateSettings('EUR', null, 'kg', 'cm', null, null, unpaidOrdersEach: null));
        self::assertSame('on_hold', $this->place('guest8@example.com', caller: 'ip:198.51.100.9')->status, 'or any number');
    }

    public function testAGuestAtAFullPlaceConfirmsTheirEmailAndIsCountedByIt(): void
    {
        $this->stocked();
        for ($i = 0; $i < 3; ++$i) {
            $this->place("guest{$i}@example.com", caller: 'ip:198.51.100.9');
        }
        $confirmations = $this->shop->emailConfirmations;
        $cart = $this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken;
        self::assertNotNull($cart);
        $refused = function (string $code, string $email, ?string $cart, string $why, ?\Closure $mayMail = null): void {
            try {
                $this->place($email, caller: 'ip:198.51.100.9', mayMail: $mayMail, cart: $cart);
                self::fail($why);
            } catch (CheckoutRuleViolation $e) {
                self::assertSame($code, $e->errorCode, $why);
            }
        };

        try {
            $this->place('Grace@example.com', caller: 'ip:198.51.100.9', cart: $cart);
            self::fail('the place is full');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame(['orillacart_confirm_email', 'Too many orders wait for a payment like this one. To order this way, confirm your email: we sent a link to Grace@example.com. Follow it, on any device, then place the order again here; or choose another way to pay.'], [$e->errorCode, $e->getMessage()]);
        }
        $this->shop->jobs->runAll();
        $sent = $this->shop->mail->ofKind('email_confirmation');
        self::assertSame(['Grace@example.com: [Lamp Shop] Confirm your email to order'], array_map(static fn($email): string => "{$email->to}: {$email->subject}", $sent));
        self::assertSame(1, preg_match('/orillacart_email=(' . EmailConfirmations::PATTERN . ')"/', $sent[0]->html, $link), 'as the checkout page knows it');
        $token = $link[1];
        self::assertStringNotContainsString($cart, $token, 'the link names neither the cart nor the mailbox');

        $refused('orillacart_confirm_email', 'Grace@example.com', $cart, 'a link sent confirms nothing until it is followed');
        // Followed wherever the mail is read: the shop keeps it, the browser that asked orders.
        $confirmations->confirm($token);
        $first = $this->place('Grace@example.com', caller: 'ip:198.51.100.9', cart: $cart);
        self::assertSame('on_hold', $first->status, 'the mailbox is hers');
        self::assertSame(new CallerKeys($this->shop->signatures)->of(EmailConfirmations::shopper('grace@example.com')), $this->shop->orderRecords->find($first->number)?->callerKey(), 'the order is counted by the mailbox, not the place');
        self::assertSame('on_hold', $this->place('grace+shop@example.com', caller: 'ip:198.51.100.9', cart: $cart)->status, 'however the address is written');
        self::assertSame('on_hold', $this->place('grace@example.com', caller: 'ip:198.51.100.9', cart: $cart)->status);
        $refused('orillacart_too_many_unpaid_orders', 'grace@example.com', $cart, 'three of hers wait');
        $this->shop->orders->markPaid($first->number, null);
        self::assertSame('on_hold', $this->place('grace@example.com', caller: 'ip:198.51.100.9', cart: $cart)->status, 'one paid makes room');

        $this->shop->mail->emails = [];
        $refused('orillacart_confirm_email', 'heidi@example.com', $cart, 'another mailbox is asked itself');
        $refused('orillacart_confirm_email', 'grace@example.com', null, 'and so is another browser typing her email');
        $refused('orillacart_too_many_unpaid_orders', 'judy@example.com', $cart, 'no more links may go: refused as before', static fn(string $email): bool => false);
        $this->shop->jobs->runAll();
        self::assertSame(['heidi@example.com', 'grace@example.com'], array_map(static fn($email): string => $email->to, $this->shop->mail->ofKind('email_confirmation')));

        foreach (['no link', '1.' . str_repeat('a', 32) . '.' . str_repeat('b', 32), substr($token, 0, -1) . (str_ends_with($token, '0') ? '1' : '0')] as $forged) {
            try {
                $confirmations->confirm($forged);
                self::fail('not a link the shop made');
            } catch (\OrillaCart\Shared\Domain\NotFound $e) {
                self::assertSame('orillacart_email_link_invalid', $e->errorCode);
            }
        }
        $this->shop->clock->modify('+25 hours');
        self::assertFalse($confirmations->confirmed('grace@example.com', $cart), 'confirmed for a day');
        try {
            $confirmations->confirm($token);
            self::fail('and the link works for a day');
        } catch (\OrillaCart\Shared\Domain\NotFound) {
        }
        $this->shop->settingsService->update(new UpdateSettings('EUR', null, 'kg', 'cm', null, null, guestsAtUnpaidLimit: 'refuse'));
        $refused('orillacart_too_many_unpaid_orders', 'kim@example.com', $cart, 'the shop asks nobody to confirm');
    }

    public function testOrdersPlacedInSomeonesEmailDoNotCountOnceTheyConfirmIt(): void
    {
        $this->stocked();
        // Someone places transfers in Ada's email from elsewhere; her own place is full of others' orders.
        for ($i = 0; $i < 3; ++$i) {
            $this->place('ada@example.com', caller: 'ip:203.0.113.7');
            $this->place("guest{$i}@example.com", caller: 'ip:198.51.100.9');
        }
        $cart = $this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken;
        self::assertNotNull($cart);
        $this->shop->emailConfirmations->confirm($this->shop->emailConfirmations->token('ada@example.com', $cart, $this->shop->clock->now()->getTimestamp()));

        self::assertSame('on_hold', $this->place(caller: 'ip:198.51.100.9', cart: $cart)->status);
    }

    public function testATryThatPlacedNoOrderTakesNoRoom(): void
    {
        $this->stocked();
        for ($i = 0; $i < 6; ++$i) {
            try {
                $this->place('guest@example.com', caller: 'ip:198.51.100.9', postcode: '');
                self::fail('no postcode');
            } catch (CheckoutRuleViolation|\OrillaCart\Shared\Domain\RuleViolation) {
            }
        }

        self::assertSame('on_hold', $this->place('guest@example.com', caller: 'ip:198.51.100.9')->status);
    }

    public function testAnOrderKeepsWhereItWasPlacedFromOnlyWhileItWaits(): void
    {
        $this->stocked();
        $key = new CallerKeys($this->shop->signatures)->of('ip:198.51.100.9');
        $waiting = $this->place('guest@example.com', caller: 'ip:198.51.100.9');
        $paid = $this->place('guest@example.com', caller: 'ip:198.51.100.9');
        $ada = $this->place(customer: $this->shop->accounts->add('ada@example.com', 'Ada'), caller: 'ip:198.51.100.9');
        $this->shop->orders->markPaid($paid->number, null);

        self::assertSame(32, \strlen($key));
        self::assertStringNotContainsString('198', $this->shop->orderRecords->find($waiting->number)?->callerKey() ?? '');
        self::assertSame([$key, null, null], array_map(fn(PlacedOrderView $order): ?string => $this->shop->orderRecords->find($order->number)?->callerKey(), [$waiting, $paid, $ada]), 'a guest\'s that waits, not a paid one, not a customer\'s');
        self::assertSame($key, new CallerKeys($this->shop->signatures)->of('ip:::ffff:198.51.100.9'), 'as the limits count callers');
    }

    public function testAnOrderPaidOnlineOrOnDeliveryIsNeverRefusedForOrdersThatWait(): void
    {
        $this->stocked();
        $this->shop->paymentMethods->update('stripe', new SavePaymentMethod('Card', true, 2, settings: ['secret_key' => 'sk_live_shop', 'webhook_secret' => 'whsec_shop']));
        $this->shop->paymentMethods->update('cash_on_delivery', new SavePaymentMethod('Cash on delivery', true, 3));
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        for ($i = 0; $i < 3; ++$i) {
            $this->place(customer: $ada);
        }

        self::assertSame('pending_payment', $this->place(customer: $ada, payment: 'stripe')->status);
        self::assertSame('on_hold', $this->place(customer: $ada, payment: 'cash_on_delivery')->status);
        self::assertSame('on_hold', $this->place(customer: $ada, payment: 'cash_on_delivery')->status, 'orders paid on delivery do not count either');
    }

    private function stocked(): void
    {
        $this->shop->stock->setLevels(StockItemRef::product(20), new SetStockLevels([new SetStockLevel($this->room, 50)]));
    }

    private function place(string $email = 'ada@example.com', ?int $customer = null, string $caller = '', string $payment = 'bank_transfer', string $postcode = '1000', ?\Closure $mayMail = null, ?string $cart = null): PlacedOrderView
    {
        // A cart of the browser's own, or the one it has, filled again.
        $owner = new CartOwner($cart ?? $this->shop->carts->add(new CartOwner(null, $customer), new AddToCart(20))->newToken, $customer);
        if ($cart !== null && ($this->shop->carts->contents($owner)->lines ?? []) === []) {
            $this->shop->carts->add($owner, new AddToCart(20));
        }

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder($email, new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', $postcode, 'BG', '+359 2 000 0000'), payment: $payment), $caller, $mayMail);
    }
}
