<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Cart;

use OrillaCart\Cart\Application\Dto\AbandonedCartView;
use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Application\Dto\CheckoutContact;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Customers\Domain\Event\PersonErased;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettings;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class AbandonedCartsTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'Desk lamp');
        $this->shop->products->save(20, new SaveProduct('40', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testAVisitorWhoAskedIsRemindedOnceAndTheLinkBringsTheCartBack(): void
    {
        $owner = $this->atCheckout('Ada@Example.com', true);
        $this->shop->clock->modify('+3 hours');
        self::assertSame(0, $this->shop->cartReminders->run(), 'not left long enough');

        $this->shop->clock->modify('+2 hours');
        self::assertSame(1, $this->shop->cartReminders->run());
        self::assertSame(0, $this->shop->cartReminders->run(), 'counted once');
        self::assertSame(['ada@example.com: [Lamp Shop] Your cart is waiting for you'], $this->shop->mail->summary());
        $html = $this->shop->mail->emails[0]->html;
        self::assertStringContainsString('Desk lamp', $html);
        self::assertSame(1, preg_match('#href="https://shop\.test/cart\?cart=([a-f0-9]{64})"#', $html, $link));

        // Opened on another browser: the cart is there, and the old cookie no longer reaches it.
        $token = $this->shop->carts->recover($link[1]);
        self::assertNotNull($token);
        self::assertSame(1, $this->shop->carts->cart(new CartOwner($token))->cart->items);
        self::assertSame(0, $this->shop->carts->cart($owner)->cart->items);

        $order = $this->shop->checkout->placeOrder(new CartOwner($token), new PlaceOrder('ada@example.com', $this->address(), payment: 'bank_transfer'));
        [$from, $until] = $this->today();
        $report = $this->shop->abandonedCarts->report($from, $until, 'EUR', 10);
        self::assertSame(['abandoned' => 1, 'value' => '40', 'reminded' => 1, 'recovered' => 1, 'recovered_value' => (string) Money::of($order->total), 'recovered_after_reminder' => 1], $report['totals']);
        self::assertSame($order->number, $report['latest'][0]->orderId);
        self::assertSame('ada@example.com', $report['latest'][0]->email);
        self::assertNull($this->shop->carts->recover($link[1]), 'the cart was ordered: the link is done');
    }

    public function testAVisitorWhoDidNotAskIsCountedButNotWrittenTo(): void
    {
        $this->atCheckout('grace@example.com', false);
        $this->shop->clock->modify('+5 hours');

        self::assertSame(0, $this->shop->cartReminders->run());
        self::assertSame([], $this->shop->mail->summary());
        [$from, $until] = $this->today();
        $report = $this->shop->abandonedCarts->report($from, $until, 'EUR', 10);
        self::assertSame(1, $report['totals']['abandoned']);
        self::assertNull($report['latest'][0]->email, 'the email is not kept without the ask');
    }

    public function testACartChangedOrBackAtCheckoutWaitsAgain(): void
    {
        $owner = $this->atCheckout('ada@example.com', true);
        $this->shop->clock->modify('+3 hours');
        $this->shop->carts->atCheckout($owner, new CheckoutContact('ada@example.com', true), null);
        $this->shop->clock->modify('+3 hours');

        self::assertSame(0, $this->shop->cartReminders->run());
        $this->shop->clock->modify('+2 hours');
        self::assertSame(1, $this->shop->cartReminders->run());
    }

    public function testTheReminderOffersTheShopsCouponWhileItCanBeUsed(): void
    {
        $this->shop->coupons->create(new SaveCoupon('COMEBACK', 'percent', '10', '10 % off'));
        $this->shop->emailSettings->save(new EmailSettings(cartReminderCoupon: 'comeback'));
        $this->atCheckout('ada@example.com', true);
        $this->shop->clock->modify('+5 hours');
        $this->shop->cartReminders->run();

        $html = $this->shop->mail->emails[0]->html;
        self::assertStringContainsString('Use the code COMEBACK at checkout.', $html);
        self::assertStringContainsString('&amp;coupon=COMEBACK"', $html);

        $this->shop->emailSettings->save(new EmailSettings(cartReminderCoupon: 'GONE'));
        $this->atCheckout('grace@example.com', true);
        $this->shop->clock->modify('+5 hours');
        $this->shop->cartReminders->run();
        self::assertStringNotContainsString('Use the code', $this->shop->mail->emails[1]->html);
    }

    public function testACustomersReminderLeadsThroughTheLoginAndTheShopCanTurnItOff(): void
    {
        $this->atCheckout('ada@example.com', true, 7);
        $this->shop->clock->modify('+5 hours');
        $this->shop->cartReminders->run();
        self::assertStringContainsString('href="https://shop.test/login?to=cart&amp;"', $this->shop->mail->emails[0]->html);

        $this->shop->emailSettings->save(new EmailSettings(disabled: [EmailKind::CartReminder]));
        $this->atCheckout('grace@example.com', true);
        $this->shop->clock->modify('+5 hours');
        self::assertSame(0, $this->shop->cartReminders->run());
        self::assertCount(1, $this->shop->mail->emails);
        [$from, $until] = $this->today();
        self::assertSame(2, $this->shop->abandonedCarts->report($from, $until, 'EUR', 10)['totals']['abandoned'], 'counted all the same');
    }

    public function testTheEmailIsForgottenWithThePersonAndWithTheCart(): void
    {
        $this->atCheckout('ada@example.com', true);
        $this->atCheckout('grace@example.com', true);
        $this->shop->emailSettings->save(new EmailSettings(disabled: [EmailKind::CartReminder]));
        $this->shop->clock->modify('+5 hours');
        $this->shop->cartReminders->run();

        $this->shop->events->dispatch(new PersonErased('ADA@example.com'));
        [$from, $until] = $this->today();
        $emails = array_map(static fn(AbandonedCartView $left): ?string => $left->email, $this->shop->abandonedCarts->report($from, $until, 'EUR', 10)['latest']);
        sort($emails);
        self::assertSame([null, 'grace@example.com'], $emails);
        foreach ($this->shop->cartRecords->carts as $cart) {
            self::assertNotSame('ada@example.com', $cart->contactEmail());
        }

        $this->shop->clock->modify('+31 days');
        self::assertSame(1, $this->shop->abandonedCarts->forgetOld());
    }

    private function atCheckout(string $email, bool $remind, ?int $customerId = null): CartOwner
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null, $customerId), new AddToCart(20))->newToken, $customerId);
        $this->shop->carts->atCheckout($owner, new CheckoutContact($email, $remind), 'de_DE');

        return $owner;
    }

    private function address(): AddressData
    {
        return new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000');
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private function today(): array
    {
        return [new \DateTimeImmutable('2026-10-17 00:00 UTC'), new \DateTimeImmutable('2026-10-19 00:00 UTC')];
    }
}
