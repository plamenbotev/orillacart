<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PayOrder;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/** Paying an order again from its receipt. */
final class PayNowTest extends TestCase
{
    private TestShop $shop;
    private int $rate;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('100', weight: '1', stockMode: 'always_available'));
        $this->shop->paymentMethods->update('stripe', new SavePaymentMethod('Card', true, settings: ['secret_key' => 'sk_live_shop', 'webhook_secret' => 'whsec_shop']));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true, 2));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Bulgaria', '5', regions: ['BG'])]))->rates[0]->id;
    }

    public function testAnOrderWhosePaymentIsHeldIsNotPaidAgainAndTheShopIsAskedToLook(): void
    {
        $placed = $this->place('stripe');
        // Stripe took the money and does not say how much: the payment waits for the shop.
        $this->shop->stripeApi->sessions['cs_test_1']['amount_total'] = null;
        $this->shop->stripeApi->complete('cs_test_1');
        $this->shop->outcomes->returned('stripe', $this->shop->paymentUrls->query('stripe', 1) + ['session_id' => 'cs_test_1'], false);

        $order = $this->shop->orders->order($placed->number);
        self::assertSame('on_hold', $order->status);
        self::assertStringContainsString('The payment through stripe (transaction pi_1) waits for the shop', (string) $order->attention);
        self::assertSame([], $this->shop->payNow->methods($order), 'no way to pay is offered');
        try {
            $this->shop->payNow->pay($this->shop->receipts->issue($placed->number), new PayOrder('stripe'));
            self::fail('paid again while the first payment is held');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_order_not_payable', $e->errorCode);
        }
        self::assertCount(1, $this->shop->stripeApi->sessions, 'no second session');
        self::assertSame('on_hold', $this->shop->orders->order($placed->number)->status);
    }

    public function testPayingAgainDoesNotStartThePaymentWindowOver(): void
    {
        $placed = $this->place('stripe');
        $this->shop->clock->modify('+2 hours');
        $this->shop->payNow->pay($this->shop->receipts->issue($placed->number), new PayOrder('stripe'));

        $this->shop->clock->modify('+61 minutes');

        self::assertSame(1, $this->shop->orders->cancelAbandoned());
        self::assertSame('cancelled', $this->shop->orders->order($placed->number)->status);
    }

    public function testAnOrderThatWaitedForATransferIsGivenTheWholeWindowToPayOnline(): void
    {
        $placed = $this->place('bank_transfer');
        $this->shop->clock->modify('+2 days');
        $this->shop->payNow->pay($this->shop->receipts->issue($placed->number), new PayOrder('stripe'));

        $this->shop->clock->modify('+2 hours');

        self::assertSame(0, $this->shop->orders->cancelAbandoned());
        self::assertSame('pending_payment', $this->shop->orders->order($placed->number)->status);
    }

    public function testAStripeSessionEndsWithThePaymentWindow(): void
    {
        $placed = $this->place('stripe');
        $start = $this->shop->clock->now()->getTimestamp();
        self::assertSame($start + 3 * 3600, $this->shop->stripeApi->requests[0]['params']['expires_at']);

        // Ten minutes left of the window: Stripe takes half an hour at least.
        $this->shop->clock->modify('+170 minutes');
        $this->shop->payNow->pay($this->shop->receipts->issue($placed->number), new PayOrder('stripe'));

        $created = array_values(array_filter($this->shop->stripeApi->requests, static fn(array $request): bool => $request['path'] === '/v1/checkout/sessions' && $request['method'] === 'POST'));
        self::assertSame($this->shop->clock->now()->getTimestamp() + 1860, $created[\count($created) - 1]['params']['expires_at'] ?? null);
    }

    private function place(string $payment): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$this->rate}", payment: $payment));
    }
}
