<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Payment\PaymentReturn;
use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\Payment\RefundRequest;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;
use OrillaCart\Extension\Shipping\PickupQuery;
use OrillaCart\Extension\Shipping\Shipment;
use OrillaCart\Extension\Shipping\ShipmentItem;
use OrillaCart\Packages\Application\Adapters\ScriptedCourier;
use OrillaCart\Packages\Application\Adapters\ScriptedPaymentGateway;
use OrillaCart\Packages\Application\Adapters\ScriptedRefundingGateway;
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
        self::assertInstanceOf(ScriptedRefundingGateway::class, $gateway, 'it declares refund');
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
        self::assertInstanceOf(ScriptedCourier::class, $method, 'it declares create_shipment');
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
        self::assertInstanceOf(ScriptedPaymentGateway::class, $broken);
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

    public function testTheOtherContractsAnswerThroughTheirAdapters(): void
    {
        $this->request('PUT', '/extensions/scripted/files/' . rawurlencode('more.ocs'), ['content' => <<<'OCS'
            cart_rule "no_thirteen" {
              fn check(basket) {
                let refusals = []
                for line in basket.lines {
                  if line.quantity == 13 { refusals = refusals|merge([cart.refuse_line(line.id, "Not thirteen of " ~ line.name ~ ".")]) }
                }
                if basket.currency == "XXX" { return [cart.refuse("Not in that currency.")] }
                return refusals
              }
            }
            order_fee "handling" {
              fn fee(query) { return query.subtotal < money("20") ? fee.of("Small order", "2.50") : null }
            }
            price_adjuster "bulk" {
              fn adjust(query) { return query.quantity >= 10 ? query.price * 0.9 : null }
            }
            order_adjuster "loyalty" {
              fn adjust(query) {
                if (query.fields.use_points ?? "") == "1" { return [price.adjustment("Points", "-2.00", null, "200 points")] }
                return query.customer_id == 7 ? [price.adjustment("Loyalty", "-1.00", null, "a regular")] : []
              }
            }
            checkout_field "vat_office" {
              label: "VAT office", kind: choice, section: billing, required: false, choices: { sofia: "Sofia", varna: "Varna" }
              fn check(answer, context) { return context.billing_country == "BG" or answer == "" ? null : "Only for Bulgaria." }
            }
            order_action "ping_warehouse" {
              label: "Ping the warehouse"
              fn available(order) { return order.status == "processing" }
              fn run(order) { return "Pinged for #" ~ order.number ~ " (" ~ order.lines|length ~ " lines)" }
            }
            order_column "warehouse_ref" { label: "Warehouse", fn value(order) { return "W-" ~ order.number } }
            product_column "shelf" { label: "Shelf", fn value(item) { return "S-" ~ item.product_id } }
            order_filter "warehouse_sent" {
              label: "Sent to the warehouse"
              fn find(text) { return text starts with "W-" ? [num(text|slice(2))] : [] }
            }
            home "warehouse_home" {
              fn tiles() { return [home.tile("Pallets", "12", "ready", "orders")] }
              fn needs_you() { return [home.line("A pallet waits", "orders", "info")] }
            }
            assistant_tool "warehouse_lookup" {
              title: "Look up a pallet", description: "Where a pallet is.", read_only: true
              input: { type: "object", properties: { pallet: { type: "string" } }, required: ["pallet"] }
              fn run(arguments, call) {
                if arguments.pallet == "none" { fail refused("No such pallet.", { looked: arguments.pallet }) }
                return { pallet: arguments.pallet, bay: 4, by: call.user_id }
              }
            }
            product_block "shelf_note" {
              title: "Shelf note", description: "Where to find it in the shop."
              fn render(product, settings) { return "<p class='shelf'>" ~ html.escape(product.name) ~ " <script>alert(1)</script></p>" }
            }
            mailing_list "crm" {
              name: "The CRM"
              fn subscribe(subscriber) { if subscriber.email ends with "@bounce.example" { fail unavailable("CRM down") } }
              fn unsubscribe(email) { return null }
              fn forget(email) { return null }
            }
            OCS], nonce: true);
        $enabled = $this->request('POST', '/extensions/scripted/enable', ['grant' => []], nonce: true);
        self::assertSame(200, $enabled->get_status(), (string) wp_json_encode($enabled->get_data()));

        $rule = $this->provider('no_thirteen');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedCartRule::class, $rule);
        $refusals = $rule->check(new \OrillaCart\Extension\Cart\CheckedCart(null, [new \OrillaCart\Extension\Cart\CheckedLine(3, 10, null, null, 'Lamp', 13, '5.00')], 'EUR'));
        self::assertSame([[3, 'Not thirteen of Lamp.']], array_map(static fn($r): array => [$r->lineId, $r->message], $refusals));

        $fee = $this->provider('handling');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedOrderFee::class, $fee);
        $query = new \OrillaCart\Extension\Checkout\FeeQuery([], '15.00', '0', null, null, 'BG', null, 'EUR');
        self::assertSame(['Small order', '2.5000'], [$fee->fee($query)?->label, $fee->fee($query)?->amount]);
        self::assertNull($fee->fee(new \OrillaCart\Extension\Checkout\FeeQuery([], '25.00', '0', null, null, 'BG', null, 'EUR')));

        $bulk = $this->provider('bulk');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedPriceAdjuster::class, $bulk);
        self::assertSame('9.0000', $bulk->adjust(new \OrillaCart\Extension\Pricing\PriceQuery(1, null, 10, null, '10.00', '10.00', 'EUR')));
        self::assertNull($bulk->adjust(new \OrillaCart\Extension\Pricing\PriceQuery(1, null, 2, null, '10.00', '10.00', 'EUR')));

        $loyalty = $this->provider('loyalty');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedOrderAdjuster::class, $loyalty);
        self::assertSame([['Loyalty', '-1.0000', 'a regular']], array_map(static fn($a): array => [$a->label, $a->amount, $a->reason], $loyalty->adjust(new \OrillaCart\Extension\Pricing\AdjustmentQuery([], 'EUR', 7))));
        self::assertSame([['Points', '-2.0000', '200 points']], array_map(static fn($a): array => [$a->label, $a->amount, $a->reason], $loyalty->adjust(new \OrillaCart\Extension\Pricing\AdjustmentQuery([], 'EUR', null, fields: ['use_points' => '1']))), 'the checkout answers reach the adjuster (PLAN 351)');

        $field = $this->provider('vat_office');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedFieldCheck::class, $field);
        $declared = $field->field();
        self::assertSame(['vat_office', 'VAT office', 'choice', 'billing', ['sofia' => 'Sofia', 'varna' => 'Varna']], [$declared->id, $declared->label, $declared->kind, $declared->section, $declared->choices]);
        $check = $declared->check;
        self::assertNotNull($check);
        self::assertSame('Only for Bulgaria.', $check->check('sofia', new \OrillaCart\Extension\Checkout\FieldContext('a@example.com', 'DE', null, null)));
        self::assertNull($check->check('sofia', new \OrillaCart\Extension\Checkout\FieldContext('a@example.com', 'BG', null, null)));

        $order = new \OrillaCart\Extension\Order\OrderSummary(100, 'processing', new \DateTimeImmutable('2026-11-07'), null, 'ada@example.com', 7, new \OrillaCart\Extension\Order\OrderAddress('Ada', 'L', '1 St', 'Sofia', '1000', 'BG', null, ''), null, [new \OrillaCart\Extension\Order\OrderLine(10, null, null, 'Lamp', 1, '5.00', '5.00', '0.83')], '5.00', '0', '0', '0.83', '5.00', '0', 'EUR', null, null, null, '', []);
        $action = $this->provider('ping_warehouse');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedOrderAction::class, $action);
        self::assertSame(['Ping the warehouse', true, 'Pinged for #100 (1 lines)'], [$action->label(), $action->isAvailable($order), $action->run($order)]);

        $column = $this->provider('warehouse_ref');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedColumn::class, $column);
        self::assertSame(['Warehouse', 'W-100'], [$column->label(), $column->value($order)]);
        $shelf = $this->provider('shelf');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedColumn::class, $shelf);
        self::assertSame('S-10', $shelf->value(new \OrillaCart\Extension\Catalog\ProductItem(10, null, 'Lamp', null, '5.00')));

        $filter = $this->provider('warehouse_sent');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedFilter::class, $filter);
        self::assertSame(['Sent to the warehouse', true, [100], []], [$filter->label(), $filter->searches(), $filter->findOrders('W-100'), $filter->findOrders('nope')]);

        $home = $this->provider('warehouse_home');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedHomeContributor::class, $home);
        self::assertSame(['Pallets', '12'], [$home->tiles()[0]->label ?? null, $home->tiles()[0]->value ?? null]);
        self::assertSame('info', $home->needsYou()[0]->tone ?? null);

        $tool = $this->provider('warehouse_lookup');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedAssistantTool::class, $tool);
        self::assertSame(['warehouse_lookup', 'scripted', 'Look up a pallet', true, null, ['pallet']], [$tool->name(), $tool->addOn(), $tool->title(), $tool->readOnly(), $tool->risk(), $tool->inputSchema()['required'] ?? null]);
        self::assertSame(['pallet' => 'P1', 'bay' => 4, 'by' => 5], $tool->run(['pallet' => 'P1'], new \OrillaCart\Extension\Assistant\ToolCall(5, null)));
        try {
            $tool->run(['pallet' => 'none'], new \OrillaCart\Extension\Assistant\ToolCall(5, null));
            self::fail('refused');
        } catch (\OrillaCart\Extension\Assistant\ToolRefused $e) {
            self::assertSame(['No such pallet.', ['looked' => 'none']], [$e->getMessage(), $e->details]);
        }

        $block = $this->provider('shelf_note');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedProductBlock::class, $block);
        $html = $block->render(new \OrillaCart\Extension\Storefront\ShownProduct(10, 'Lamp <3', '/lamp', null, '5.00', null, true, 3, null, 0), []);
        self::assertStringContainsString('Lamp &lt;3', $html);
        self::assertStringNotContainsString('<script', $html, 'cleaned as a post is');

        $list = $this->provider('crm');
        self::assertInstanceOf(\OrillaCart\Packages\Application\Adapters\ScriptedMailingList::class, $list);
        $list->subscribe(new \OrillaCart\Extension\Marketing\Subscriber('ada@example.com', 'Ada', 'L', 'yes', new \DateTimeImmutable(), 'checkout'));
        try {
            $list->subscribe(new \OrillaCart\Extension\Marketing\Subscriber('x@bounce.example', 'X', 'Y', 'yes', new \DateTimeImmutable(), 'checkout'));
            self::fail('unavailable');
        } catch (ProviderUnavailable $e) {
            self::assertStringContainsString('CRM down', $e->getMessage());
        }
        self::assertSame(0, $this->packages->package('scripted')->failures, 'none of these counted as a failure');
    }

    private function provider(string $id): \OrillaCart\Packages\Application\Adapters\ScriptedProvider
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
