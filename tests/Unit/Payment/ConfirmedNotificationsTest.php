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
use OrillaCart\Payment\Application\PaymentOutcomes;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Extension\ConfirmingPayGateway;
use PHPUnit\Framework\TestCase;

/** A gateway whose notifications the shop confirms, as a scripted one's. */
final class ConfirmedNotificationsTest extends TestCase
{
    private TestShop $shop;
    private ConfirmingPayGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new ConfirmingPayGateway();
        $this->shop = new TestShop(gateways: [$this->gateway]);
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update(ConfirmingPayGateway::ID, new SavePaymentMethod('Confirm Pay', true));
    }

    public function testTheNotificationAddressCarriesTheShopsKeyForTheGateway(): void
    {
        $url = $this->shop->paymentUrls->notificationUrl(ConfirmingPayGateway::ID);

        self::assertStringEndsWith('/webhook?key=' . PaymentOutcomes::notificationKey($this->shop->signatures, ConfirmingPayGateway::ID), $url);
        self::assertNotSame(PaymentOutcomes::notificationKey($this->shop->signatures, 'other_pay'), PaymentOutcomes::notificationKey($this->shop->signatures, ConfirmingPayGateway::ID));
    }

    public function testANotificationWithoutTheKeyIsNeverRead(): void
    {
        $placed = $this->place();

        foreach (['no key' => [], 'a wrong key' => ['key' => 'guessed'], 'another gateway\'s key' => ['key' => PaymentOutcomes::notificationKey($this->shop->signatures, 'stripe')]] as $why => $query) {
            try {
                $this->shop->outcomes->notified(ConfirmingPayGateway::ID, new Notification([], '{"ref":"REF-1"}', $query));
                self::fail($why);
            } catch (PaymentRuleViolation $e) {
                self::assertSame('orillacart_invalid_notification', $e->errorCode, $why);
            }
        }
        self::assertSame([], $this->gateway->read);
        self::assertSame('pending_payment', $this->shop->orders->order($placed->number)->status);
    }

    public function testMoneyANotificationTellsOfIsAskedAboutBeforeItPaysTheOrder(): void
    {
        $placed = $this->place();

        // Someone who learned the address says the payment was made; the provider knows of no money.
        $this->shop->outcomes->notified(ConfirmingPayGateway::ID, $this->notification('{"ref":"REF-1"}'));
        self::assertSame(['REF-1'], $this->gateway->checked);
        self::assertSame('pending_payment', $this->shop->orders->order($placed->number)->status);

        $this->gateway->provider['REF-1'] = 'paid';
        $this->shop->outcomes->notified(ConfirmingPayGateway::ID, $this->notification('{"ref":"REF-1"}'));
        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['processing', 'TX-REF-1'], [$order->status, $order->transactionId]);
    }

    public function testANotificationNamingAPaymentTheShopDidNotStartAsksNothing(): void
    {
        $this->place();

        $this->shop->outcomes->notified(ConfirmingPayGateway::ID, $this->notification('{"ref":"REF-9"}'));

        self::assertSame([], $this->gateway->checked);
    }

    private function notification(string $body): Notification
    {
        return new Notification([], $body, ['key' => PaymentOutcomes::notificationKey($this->shop->signatures, ConfirmingPayGateway::ID)]);
    }

    private function place(): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: ConfirmingPayGateway::ID));
    }
}
