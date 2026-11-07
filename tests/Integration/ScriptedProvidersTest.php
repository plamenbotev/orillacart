<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Payment\PaymentReturn;
use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\Payment\RefundRequest;
use OrillaCart\Extension\Payment\RefundsPayments;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;
use OrillaCart\Extension\Shipping\CreatesShipments;
use OrillaCart\Extension\Shipping\PickupQuery;
use OrillaCart\Extension\Shipping\Shipment;
use OrillaCart\Extension\Shipping\ShipmentItem;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Packages\Application\ScriptedRegistrant;
use OrillaCart\Packages\Domain\Package;

/**
 * Design 56: a payment gateway and a shipping method written as scripts answer the add-on API's contracts through
 * the adapters, with the scripts' failures turned into what the shop expects, and an extension that keeps failing
 * stopped.
 */
final class ScriptedProvidersTest extends RestTestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "scripted" {
          name: "Scripted providers"
          version: "1.0"
          needs: { }
        }

        payment_gateway "scriptpay" {
          name: "ScriptPay"
          settings { api_key: secret { label: "API key", required: true } }

          fn start(session, settings) {
            if session.amount > money("1000") { fail refused("ScriptPay takes payments up to 1000.") }
            return payment.redirect("https://pay.example/?ref=P" ~ session.payment_id ~ "&key=" ~ settings.api_key, "P" ~ session.payment_id)
          }
          fn reference(back) { return back.query.ref }
          fn returned(reference, back, settings) {
            return back.cancelled ? payment.cancelled(reference) : payment.completed(reference, "TX-" ~ reference, money("12.50"), "EUR")
          }
          fn notified(notification, settings) {
            if notification.headers["x-sign"] != settings.api_key { fail refused("not signed") }
            let event = notification.json
            if event == null { return null }
            return event.status == "paid" ? payment.completed(event.ref, "TX-" ~ event.ref) : payment.failed(event.ref)
          }
          fn refund(request, settings) {
            if request.amount > money("50") { fail refused("Refunds up to 50 only.") }
            return payment.refund_done("R-" ~ request.id)
          }
        }

        shipping_method "scriptship" {
          name: "ScriptShip"
          settings { account: text { label: "Account", required: true } }

          fn quote(shipment, settings) {
            if shipment.postcode == "9999" { fail unavailable("The courier is down.") }
            if shipment.country != "BG" { return [] }
            let base = money("3") + money(shipment.weight)
            return [shipping.rate("economy", "Economy", base, 3), shipping.rate("office", "To an office", base - money("1"), 2, true)]
          }
          fn pickup_points(query, settings) {
            return [shipping.pickup_point("SOF-1", "Office Vitosha", "12 Vitosha Blvd", "9–18")]
          }
          fn create_shipment(request, settings) {
            return shipping.booked("SS-" ~ request.reference, "https://track.example/" ~ request.reference)
          }
          fn track(number, settings) { return shipping.tracking("on_the_way", "Left the depot") }
        }

        payment_gateway "brokenpay" {
          name: "BrokenPay"
          fn start(session, settings) { let x = 1 / 0 }
        }
        OCS;

    private PackageService $packages;
    private ScriptedRegistrant $registrant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        // One container: the registrant's runner and the test read the same entities.
        $container = self::kernel()->container();
        $packages = $container->get(PackageService::class);
        \assert($packages instanceof PackageService);
        $this->packages = $packages;
        $registrant = $container->get(ScriptedRegistrant::class);
        \assert($registrant instanceof ScriptedRegistrant);
        $this->registrant = $registrant;
        foreach ($this->packages->all() as $existing) {
            $this->packages->uninstall($existing->id);
        }
        $this->request('POST', '/extensions', ['id' => 'scripted', 'name' => 'Scripted', 'files' => ['main.ocs' => self::SCRIPT]], nonce: true);
        $enabled = $this->request('POST', '/extensions/scripted/enable', ['grant' => []], nonce: true);
        self::assertSame(200, $enabled->get_status(), (string) wp_json_encode($enabled->get_data()));
    }

    public function testAScriptedGatewayAnswersThePaymentContract(): void
    {
        $gateway = $this->provider('scriptpay');
        self::assertInstanceOf(PaymentGateway::class, $gateway);
        self::assertInstanceOf(RefundsPayments::class, $gateway, 'it declares refund');
        self::assertSame('ScriptPay', $gateway->defaultName());
        self::assertSame(['api_key'], array_map(static fn($setting): string => $setting->key, $gateway->settings()));
        self::assertSame('Enter aPI key.', $gateway->notReady(new Settings([]), 'EUR', 2), 'a required setting left empty');
        $settings = new Settings(['api_key' => 'k1']);
        self::assertNull($gateway->notReady($settings, 'EUR', 2));

        $start = $gateway->start(new PaymentSession(7, 100, '12.50', 'EUR', 'https://shop/back', 'https://shop/cancel'), $settings);
        self::assertSame(['https://pay.example/?ref=P7&key=k1', 'P7'], [$start->redirectUrl, $start->reference]);

        self::assertSame('P7', $gateway->reference(new PaymentReturn(['ref' => 'P7'], false)));
        $outcome = $gateway->returned('P7', new PaymentReturn(['ref' => 'P7'], false), $settings);
        self::assertSame(['P7', 'completed', 'TX-P7', '12.5000', 'EUR'], [$outcome->reference, $outcome->status, $outcome->transactionId, $outcome->amount, $outcome->currency]);
        self::assertSame('cancelled', $gateway->returned('P7', new PaymentReturn([], true), $settings)->status);

        $paid = $gateway->notified(new Notification(['X-Sign' => 'k1'], (string) json_encode(['ref' => 'P7', 'status' => 'paid'])), $settings);
        self::assertSame('completed', $paid?->status);
        self::assertNull($gateway->notified(new Notification(['X-Sign' => 'k1'], 'not json'), $settings), 'nothing to say');
        try {
            $gateway->notified(new Notification(['X-Sign' => 'wrong'], '{}'), $settings);
            self::fail('A refused notification is invalid');
        } catch (\OrillaCart\Extension\Payment\InvalidNotification $e) {
            self::assertSame('not signed', $e->getMessage());
        }

        self::assertSame('R-r1', $gateway->refund(new RefundRequest('r1', 100, 'P7', 'TX-P7', '10.00', 'EUR'), $settings)->reference);
        try {
            $gateway->refund(new RefundRequest('r2', 100, 'P7', 'TX-P7', '80.00', 'EUR'), $settings);
            self::fail('A refused refund');
        } catch (\OrillaCart\Extension\Payment\RefundRefused $e) {
            self::assertSame('Refunds up to 50 only.', $e->getMessage());
        }

        try {
            $gateway->start(new PaymentSession(8, 101, '5000.00', 'EUR', 'https://shop/back', 'https://shop/cancel'), $settings);
            self::fail('Refused at start');
        } catch (ProviderUnavailable $e) {
            self::assertStringContainsString('ScriptPay takes payments up to 1000.', $e->getMessage());
        }
        self::assertSame(0, $this->packages->package('scripted')->failures, 'refusing is an answer, not a failure');
    }

    public function testAScriptedShippingMethodQuotesBooksAndTracks(): void
    {
        $method = $this->provider('scriptship');
        self::assertInstanceOf(ShippingMethod::class, $method);
        self::assertInstanceOf(CreatesShipments::class, $method, 'it declares create_shipment');
        self::assertFalse($method->isReady(new Settings([])));
        $settings = new Settings(['account' => 'A-1']);
        self::assertTrue($method->isReady($settings));

        $shipment = new Shipment('BG', null, '1000', [new ShipmentItem(2, '1.5'), new ShipmentItem(1, '0.5')], '40.00', 'EUR', 'kg', 'cm');
        $rates = $method->quote($shipment, $settings);
        self::assertSame([['economy', 'Economy', '6.5000', 3, false], ['office', 'To an office', '5.5000', 2, true]], array_map(static fn($rate): array => [$rate->code, $rate->name, $rate->price, $rate->deliveryDays, $rate->toPickupPoint], $rates));
        self::assertSame([], $method->quote(new Shipment('DE', null, '10115', [], '40.00', 'EUR', 'kg', 'cm'), $settings), 'not served');
        try {
            $method->quote(new Shipment('BG', null, '9999', [], '40.00', 'EUR', 'kg', 'cm'), $settings);
            self::fail('The courier is down');
        } catch (ProviderUnavailable $e) {
            self::assertStringContainsString('The courier is down.', $e->getMessage());
        }
        self::assertSame('SOF-1', $method->pickupPoints(new PickupQuery('office', 'BG', '1000', 'Sofia'), $settings)[0]->id ?? null);

        $booked = $method->createShipment(new \OrillaCart\Extension\Shipping\ShipmentRequest('100', 'economy', 'Ada', '+359', 'ada@example.com', '1 Street', 'Sofia', '1000', 'BG', null, null, $shipment, null, '40.00'), $settings);
        self::assertSame(['SS-100', 'https://track.example/100'], [$booked->number, $booked->trackingUrl]);
        self::assertSame(['on_the_way', 'Left the depot'], [$method->track('SS-100', $settings)->status, $method->track('SS-100', $settings)->description]);
    }

    public function testAnExtensionThatKeepsBreakingIsStoppedAndItsProvidersGo(): void
    {
        $broken = $this->provider('brokenpay');
        self::assertInstanceOf(PaymentGateway::class, $broken);
        // Each failure is logged, the stop as an error: the test site's logger prints them.
        $this->expectOutputRegex('/WARNING: The extension "scripted" failed: Division by zero.*ERROR: The extension "scripted" failed 10 times in a row and was stopped/s');
        $session = new PaymentSession(9, 102, '1.00', 'EUR', 'https://shop/back', 'https://shop/cancel');
        for ($attempt = 1; $attempt <= Package::FAILURES_TO_STOP; ++$attempt) {
            try {
                $broken->start($session, new Settings([]));
                self::fail('A broken script cannot start a payment');
            } catch (ProviderUnavailable $e) {
                self::assertStringContainsString('Division by zero', $e->getMessage());
            }
        }
        $package = $this->packages->package('scripted');
        self::assertSame(['stopped', Package::FAILURES_TO_STOP], [$package->state, $package->failures]);
        self::assertStringContainsString('Division by zero', (string) $package->lastProblem);
        self::assertSame([], $this->packages->loaded(), 'a stopped extension registers nothing');

        $enabled = $this->data($this->request('POST', '/extensions/scripted/enable', ['grant' => []], nonce: true));
        self::assertSame(['enabled', 0], [$enabled['state'], $enabled['failures']], 'a manager enables it again after reading the problem');
    }

    private function provider(string $id): object
    {
        foreach ($this->packages->loaded() as $loaded) {
            foreach ($this->registrant->providers($loaded) as $provider) {
                if ($provider->id() === $id) {
                    return $provider;
                }
            }
        }
        self::fail("No provider {$id}");
    }
}
