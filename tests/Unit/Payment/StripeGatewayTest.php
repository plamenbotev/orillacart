<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Payment;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Settings;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Application\Stripe\StripeGateway;
use OrillaCart\Payment\Domain\PaymentNotFound;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Payment\FakeStripeApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StripeGatewayTest extends TestCase
{
    private const string SECRET = 'whsec_shop';

    private TestShop $shop;
    /** How many events Stripe sent: each has an id of its own. */
    private int $events = 0;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('stripe', new SavePaymentMethod('Card', true, settings: ['secret_key' => 'sk_live_shop', 'webhook_secret' => self::SECRET]));
    }

    public function testACustomerPaysOnStripesPageAndComesBackToAPaidOrder(): void
    {
        $placed = $this->place();

        $created = $this->shop->stripeApi->requests[0];
        self::assertSame(['POST', '/v1/checkout/sessions'], [$created['method'], $created['path']]);
        self::assertStringEndsWith('&session_id={CHECKOUT_SESSION_ID}', self::sent($created['params'], 'success_url'));
        self::assertSame('ada@example.com', $created['params']['customer_email']);
        self::assertSame(['orillacart_order' => (string) $placed->number, 'orillacart_payment' => '1'], $created['params']['metadata']);
        self::assertSame('E-book', self::sent($created['params'], 'line_items.0.price_data.product_data.name'), 'the items listed, as they add up');
        self::assertMatchesRegularExpression('/^orillacart-[0-9a-f]{12}-payment-1$/', (string) $created['key']);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $placed->redirectUrl);

        $this->shop->stripeApi->complete('cs_test_1');
        self::assertSame($placed->receiptUrl, $this->shop->outcomes->returned('stripe', $this->back('cs_test_1'), false));

        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['processing', 'pi_1'], [$order->status, $order->transactionId]);
    }

    public function testTheWayBackFromStripeLeadsToTheReceiptForAWeekAndThenToFindingTheOrder(): void
    {
        $placed = $this->place();
        $this->shop->stripeApi->complete('cs_test_1');
        $back = $this->back('cs_test_1');

        $this->shop->clock->modify('+7 days');
        self::assertSame('https://shop.test/account/?orillacart_find=expired', $this->shop->outcomes->returned('stripe', $back, false), 'no new receipt link for an old address');
        self::assertSame('pending_payment', $this->shop->orders->order($placed->number)->status, 'nor does it settle the payment: the notification does');

        try {
            $this->shop->outcomes->returned('stripe', ['day' => (string) ((int) $back['day'] + 7)] + $back, false);
            self::fail('a day the address was not signed for was taken');
        } catch (PaymentNotFound) {
        }
    }

    public function testAWebhookSettlesTheOrderOfACustomerWhoNeverCameBack(): void
    {
        $placed = $this->place();

        $session = $this->shop->stripeApi->complete('cs_test_1');
        $session['payment_intent'] = 'pi_1';
        $this->shop->outcomes->notified('stripe', $this->event('checkout.session.completed', $session));

        self::assertSame('processing', $this->orderStatus($placed));
    }

    public function testABankDebitHoldsTheOrderUntilTheMoneyArrives(): void
    {
        $cleared = $this->place();
        $bounced = $this->place();
        $this->shop->stripeApi->complete('cs_test_1', 'unpaid', 'processing');
        $this->shop->stripeApi->complete('cs_test_2', 'unpaid', 'processing');
        $this->shop->outcomes->returned('stripe', $this->back('cs_test_1'), false);
        $this->shop->outcomes->returned('stripe', $this->back('cs_test_2'), false);
        $statuses = fn(): array => [$this->orderStatus($cleared), $this->orderStatus($bounced)];
        self::assertSame(['on_hold', 'on_hold'], $statuses());

        $this->shop->clock->modify('+2 days');
        $this->shop->orders->cancelAbandoned();
        self::assertSame('on_hold', $this->orderStatus($cleared), 'money on its way is not an abandoned order');

        $this->shop->outcomes->notified('stripe', $this->event('checkout.session.async_payment_succeeded', ['payment_status' => 'paid'] + $this->shop->stripeApi->sessions['cs_test_1']));
        $this->shop->outcomes->notified('stripe', $this->event('checkout.session.async_payment_failed', $this->shop->stripeApi->sessions['cs_test_2']));

        self::assertSame(['processing', 'failed'], $statuses());
    }

    public function testACustomerWhoGoesBackClosesTheSessionNotTheOrder(): void
    {
        $placed = $this->place();

        // Stripe's cancel address names no session: the signed address names the payment.
        $query = $this->shop->paymentUrls->query('stripe', 1);
        self::assertSame($placed->receiptUrl, $this->shop->outcomes->returned('stripe', $query, true));

        self::assertSame('expired', $this->shop->stripeApi->sessions['cs_test_1']['status'], 'it cannot be paid after the customer chose another way');
        self::assertSame('pending_payment', $this->orderStatus($placed), 'it may still be paid, until the time for unpaid orders runs out');
    }

    public function testACustomerWhoPaidInAnotherTabAndWentBackInTheFirstHasStillPaid(): void
    {
        $placed = $this->place();
        $this->shop->stripeApi->complete('cs_test_1');

        $this->shop->outcomes->returned('stripe', $this->shop->paymentUrls->query('stripe', 1), true);

        self::assertSame('processing', $this->orderStatus($placed));
    }

    public function testOnlyNotificationsStripeSignedLatelyWithTheShopsSecretAreRead(): void
    {
        $placed = $this->place();
        $session = $this->shop->stripeApi->complete('cs_test_1');

        foreach (['another secret' => $this->event('checkout.session.completed', $session, 'whsec_other'), 'replayed an hour later' => $this->event('checkout.session.completed', $session, at: -3600)] as $why => $notification) {
            try {
                $this->shop->outcomes->notified('stripe', $notification);
                self::fail($why);
            } catch (PaymentRuleViolation $e) {
                self::assertSame('orillacart_invalid_notification', $e->errorCode, $why);
            }
        }
        self::assertSame('pending_payment', $this->orderStatus($placed));
    }

    public function testRefundsGoBackToThePaymentAndTheirWebhookRecordsNothingTwice(): void
    {
        $placed = $this->paid();

        $view = $this->shop->refunds->refund($placed->number, new MakeRefund('8', [new RefundLine(0, 1)], reason: 'Arrived broken.'), 7);

        $asked = $this->shop->stripeApi->requests[\count($this->shop->stripeApi->requests) - 1];
        self::assertSame('/v1/refunds', $asked['path']);
        self::assertSame(['pi_1', 800, 'Arrived broken.'], [$asked['params']['payment_intent'], $asked['params']['amount'], self::sent($asked['params'], 'metadata.note')]);
        self::assertSame(['done', 're_1'], [$view->refunds[0]->status, $view->refunds[0]->providerReference]);

        // Stripe tells of the shop's own refund, and of one made in its dashboard.
        $this->shop->outcomes->notified('stripe', $this->event('refund.created', $this->shop->stripeApi->refunds['re_1']));
        $this->shop->outcomes->notified('stripe', $this->event('refund.updated', ['id' => 're_dash', 'object' => 'refund', 'amount' => 0, 'currency' => 'eur', 'payment_intent' => 'pi_1', 'status' => 'pending', 'metadata' => []]));
        self::assertCount(1, $this->shop->refunds->forOrder($placed->number)->refunds, 'not done yet');

        self::assertSame('refunded', $this->shop->orders->order($placed->number)->status);
    }

    public function testARefundMadeInStripesDashboardIsRecorded(): void
    {
        $placed = $this->paid();

        $this->shop->outcomes->notified('stripe', $this->event('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'refunds' => ['data' => [['id' => 're_dash', 'amount' => 300, 'currency' => 'eur', 'status' => 'succeeded', 'metadata' => []]]]]));

        $refunds = $this->shop->refunds->forOrder($placed->number)->refunds;
        self::assertSame([['3', 're_dash']], array_map(static fn($refund): array => [$refund->amount, $refund->providerReference], $refunds));
    }

    public function testARefundStripeRefusesSaysWhy(): void
    {
        $placed = $this->paid();
        $this->shop->stripeApi->refuses = ['request' => 'POST /v1/refunds', 'code' => 'charge_disputed', 'message' => 'Charge ch_1 has been charged back.'];

        try {
            $this->shop->refunds->refund($placed->number, new MakeRefund('8'), 7);
            self::fail('refused');
        } catch (PaymentRuleViolation $e) {
            self::assertSame('Card refused the refund: The payment is disputed: Stripe refunds it only through the dispute.', $e->getMessage(), 'named as checkout names the method');
        }
    }

    public function testADisputeFlagsItsOrderForTheShop(): void
    {
        $placed = $this->paid();

        $this->shop->outcomes->notified('stripe', $this->event('charge.dispute.created', ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_1', 'reason' => 'product_not_received', 'status' => 'needs_response']));

        $order = $this->shop->orders->order($placed->number);
        self::assertSame('processing', $order->status, 'the status stays');
        self::assertSame('The customer disputed the payment with their bank (product not received). Answer it in the Stripe dashboard.', $order->attention);
        self::assertTrue($this->shop->orders->orders(new OrderQuery(attention: true), forShop: true)->orders[0]->needsAttention ?? false);
    }

    public function testPaymentsNobodyToldTheShopOfAreAskedAboutEveryHour(): void
    {
        $paid = $this->place();
        $lapsed = $this->place();
        // Both customers closed the window; no webhook came.
        $this->shop->stripeApi->complete('cs_test_1');
        $this->shop->stripeApi->sessions['cs_test_2']['status'] = 'expired';
        $this->shop->clock->modify('+16 minutes');

        self::assertSame(2, $this->shop->outcomes->reconcile());

        self::assertSame(['processing', 'cancelled'], [$this->orderStatus($paid), $this->orderStatus($lapsed)], 'a session that lapsed unpaid ends the order, as a PayPal order never approved does');
    }

    public function testLivePaymentsNeedALiveKeyAndTheWebhook(): void
    {
        // Secrets left out of a save are kept: a shop that never gave one.
        $this->shop = new TestShop();

        self::assertSame('Enter the webhook signing secret: live payments need it.', $this->readiness(['secret_key' => 'sk_live_shop']));
        self::assertSame('The key is a test key: turn on Test mode, or enter the live key.', $this->readiness(['secret_key' => 'sk_test_shop', 'webhook_secret' => self::SECRET]));
        self::assertSame('Test mode takes a test key (sk_test_…).', $this->readiness(['secret_key' => 'sk_live_shop', 'sandbox' => true]));
        self::assertSame('The secret key starts with sk_ or rk_: copy it from Developers → API keys.', $this->readiness(['secret_key' => 'pk_live_shop', 'webhook_secret' => self::SECRET]));
        self::assertNull($this->readiness(['secret_key' => 'rk_test_shop', 'sandbox' => true]), 'test payments do without the webhook');
    }

    public function testAShopPricingDinarsInFilsIsNotOfferedStripeUntilItPricesInHundredths(): void
    {
        $this->shop->settings->save(new StoreSettings(new Currency('KWD'), null, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'KW'));

        self::assertSame('Stripe takes amounts in KWD with 2 decimals; set the shop’s price decimals to 2.', $this->shop->paymentMethods->show('stripe')->notReady);

        $this->shop->settings->save(new StoreSettings(new Currency('KWD'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'KW'));
        self::assertNull($this->shop->paymentMethods->show('stripe')->notReady);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function amounts(): iterable
    {
        yield 'euros in cents' => ['EUR', '12.34', 1234];
        yield 'yen whole' => ['JPY', '1500', 1500];
        yield 'dinars in fils, the last 0' => ['KWD', '12.34', 12340];
        yield 'krónur whole, in hundredths' => ['ISK', '1500', 150000];
    }

    #[DataProvider('amounts')]
    public function testAmountsAreCountedInTheCurrencysSmallestUnitAsStripeCountsThem(string $currency, string $amount, int $minor): void
    {
        self::assertSame($minor, StripeGateway::minor(Money::of($amount), $currency));
        self::assertTrue(Money::of(StripeGateway::major($minor, $currency))->equals(Money::of($amount)));
    }

    public function testTheTestButtonReadsTheBalanceWithTheKey(): void
    {
        $gateway = new StripeGateway($api = new FakeStripeApi(), $this->shop->clock);

        self::assertNull($gateway->test(new Settings(['secret_key' => 'sk_test_shop'])));
        self::assertSame('/v1/balance', $api->requests[0]['path']);
        self::assertSame('Enter the secret key.', $gateway->test(new Settings([])));
    }

    private function place(): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'stripe'));
    }

    private function paid(): PlacedOrderView
    {
        $placed = $this->place();
        $this->shop->stripeApi->complete('cs_test_1');
        $this->shop->outcomes->returned('stripe', $this->back('cs_test_1'), false);

        return $placed;
    }

    /**
     * What the shop sent Stripe at a dotted path of its parameters, such as "metadata.note".
     *
     * @param array<string, mixed> $params
     */
    private static function sent(array $params, string $path): string
    {
        $value = $params;
        foreach (explode('.', $path) as $key) {
            self::assertIsArray($value);
            $value = $value[$key] ?? null;
        }
        self::assertIsString($value);

        return $value;
    }

    /**
     * Why checkout cannot offer Stripe with these settings saved; null when it can.
     *
     * @param array<string, string|bool> $settings
     */
    private function readiness(array $settings): ?string
    {
        $this->shop->paymentMethods->update('stripe', new SavePaymentMethod('Card', true, settings: $settings));

        return $this->shop->paymentMethods->show('stripe')->notReady;
    }

    private function orderStatus(PlacedOrderView $placed): string
    {
        return $this->shop->orders->order($placed->number)->status;
    }

    /**
     * Where Stripe sends the customer who paid: the return address, with the session's id.
     *
     * @return array<string, string>
     */
    private function back(string $session): array
    {
        $payment = $this->shop->paymentRecords->findByReference('stripe', $session);
        self::assertNotNull($payment);

        return $this->shop->paymentUrls->query('stripe', $payment->id()) + ['session_id' => $session];
    }

    /**
     * An event as Stripe sends it, signed with the endpoint's secret.
     *
     * @param array<string, mixed> $object
     */
    private function event(string $type, array $object, string $secret = self::SECRET, int $at = 0): Notification
    {
        $body = (string) json_encode(['id' => 'evt_' . ++$this->events, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
        $time = $this->shop->clock->now()->getTimestamp() + $at;

        return new Notification(['stripe-signature' => "t={$time},v1=" . hash_hmac('sha256', "{$time}.{$body}", $secret)], $body);
    }
}
