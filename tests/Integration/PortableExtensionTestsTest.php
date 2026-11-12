<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Exchange\Application\AddOnProductWriting;
use OrillaCart\Exchange\Application\ProductRows;
use OrillaCart\Extension\Catalog\ProductDraft;
use OrillaCart\Packages\Application\PackageTests;

/**
 * An extension's tests that run on any shop: they make the customers, products and orders they need, in the
 * test's rolled-back transaction and without the permissions a live extension would ask for to do so, and leave
 * none of it behind. With them, what the trade extension's probes found: stock modes refused, a draft refused as a
 * matter of quantity, customers named in records unchecked, and the files sent to test and debug replacing the
 * saved ones.
 */
final class PortableExtensionTestsTest extends RestTestCase
{
    private const string ADDRESS = '{ first_name: "Ada", last_name: "Lovelace", street: "1 Navy Way", city: "Arlington", postcode: "22201", country: "US", state: "VA", phone: "555" }';

    private const string PROBE = <<<'OCS'
        extension "probe" { name: "Probe", needs: { customers: read, orders: read } }
        object "note" { fields: { customer: customer, text: text } }
        fields on customer { tier: text }

        test "makes what it needs on any shop" fn() {
          let with = shop.customers.create({ email: "probe-with@example.com", first_name: "Ada", last_name: "Lovelace", billing: ADDRESS })
          assert with.billing.city == "Arlington" and with.shipping_address.city == "Arlington", json.text(with)
          assert with.addresses_from_order == null and with.orders == 0, json.text(with)
          let without = shop.customers.create({ email: "probe-without@example.com", first_name: "Bo", last_name: "Lee" })
          assert without.billing == null and without.shipping_address == null and without.addresses_from_order == null, json.text(without)

          let p = shop.products.create({ name: "Probe guide", price: "9.00", type: "virtual", sku: "PROBE-GUIDE", stock_mode: "always_available" })
          assert p.published == false
          let fails = fn(work) {
            try { work() } catch e { return e.kind ~ ": " ~ e.message }
            return "it did not fail"
          }
          let draft = fails(fn() { shop.orders.create({ email: with.email, billing: with.billing, items: [{ product_id: p.id, quantity: 1 }] }) })
          assert draft starts with "refused" and "not published" in draft, draft
          shop.products.publish(p.id)
          let quoted = shop.orders.quote({ email: with.email, billing: with.billing, items: [{ product_id: p.id, quantity: 1 }] })
          assert quoted.needs_shipping == false and quoted.shipping_options == [] and quoted.subtotal > money("0"), json.text(quoted)

          let first = shop.orders.create({ email: with.email, customer_id: with.id, billing: with.billing, items: [{ product_id: p.id, quantity: 2 }] })
          let second = shop.orders.create({ email: without.email, customer_id: without.id, billing: ADDRESS, items: [{ product_id: p.id, quantity: 1 }] })
          assert first.customer_id == with.id and first.shipping_option == null, json.text(first)
          let again = shop.customers.find(without.id)
          assert again.addresses_from_order == second.number and again.billing.street == "1 Navy Way", json.text(again)
          assert shop.customers.find(with.id).addresses_from_order == null, "the account keeps its own"

          let found = shop.customers.search({ email: "PROBE-WITH@example.com" })
          assert len(found) == 1 and found[0].id == with.id, json.text(found)
          let worded = shop.customers.search({ words: "Lovelace" })
          assert len(worded) == 1 and worded[0].id == with.id, json.text(worded)
          assert len(shop.customers.search({ email: "nobody-here@example.com" })) == 0

          objects.note.create({ customer: with.id, text: "made in the test" })
          fields.set("customer", without.id, "tier", "gold")
          assert fields.get("customer", without.id, "tier") == "gold"
        }

        test "what is not the shop's is refused" fn() {
          let fails = fn(work) {
            try { work() } catch e { return e.message }
            return "it did not fail"
          }
          let m = fails(fn() { objects.note.create({ customer: 999999, text: "x" }) })
          assert "no customer 999999" in m, m
          m = fails(fn() { fields.set("customer", 999999, "tier", "gold") })
          assert "no customer 999999" in m, m
          m = fails(fn() { shop.products.publish(OTHER) })
          assert "a product the test made" in m, m
          m = fails(fn() { shop.products.add_variation(OTHER, { options: { Colour: "Red" } }) })
          assert "a product the test made" in m, m
        }

        test "the stock modes it lists" fn() {
          let tracked = shop.products.create({ name: "Probe tracked", price: "1.00", stock_mode: "tracked" })
          let always = shop.products.create({ name: "Probe always", price: "1.00", stock_mode: "always_available" })
          let never = shop.products.create({ name: "Probe never", price: "1.00", stock_mode: "unavailable" })
          assert always.available == null, json.text(always)
          assert never.for_sale == false, json.text(never)
          let refused = false
          try { shop.products.create({ name: "Probe nonsense", price: "1.00", stock_mode: "nonsense" }) } catch e {
            assert "is not one of" in e.message, e.message
            refused = true
          }
          assert refused, "nonsense is not a stock mode"
          assert tracked.id > 0
        }
        OCS;

    private ?int $other = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        $this->request('DELETE', '/extensions/probe', nonce: true);
        $writing = self::kernel()->container()->get(AddOnProductWriting::class);
        self::assertInstanceOf(AddOnProductWriting::class, $writing);
        $this->other = $writing->create(new ProductDraft(name: 'Not the probe\'s', price: '3.00'))->id;
    }

    protected function tearDown(): void
    {
        $this->request('DELETE', '/extensions/probe', nonce: true);
        if ($this->other !== null) {
            wp_delete_post($this->other, true);
        }
        parent::tearDown();
    }

    public function testATestMakesCustomersAProductAndOrdersAndLeavesNoneBehind(): void
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $orders = static fn(): int => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orillacart_orders");
        $before = $orders();
        $this->install(['main.ocs' => $this->probe()]);

        $tests = $this->tests();
        self::assertCount(3, $tests);
        foreach ($tests as $test) {
            self::assertTrue($test['ok'], (string) wp_json_encode($test));
        }
        $effects = array_column($tests[0]['effects'], 'function');
        self::assertSame(['shop.customers.create', 'shop.customers.create', 'shop.products.create', 'shop.orders.create', 'shop.products.publish', 'shop.orders.create', 'shop.orders.create'], $effects, 'each thing made is listed, the refused order too');

        self::assertFalse(email_exists('probe-with@example.com'), 'the customers went with the test');
        self::assertFalse(email_exists('probe-without@example.com'));
        $lookup = self::kernel()->container()->get(ProductLookup::class);
        self::assertInstanceOf(ProductLookup::class, $lookup);
        self::assertNull($lookup->withSku('PROBE-GUIDE'), 'and the product');
        self::assertSame($before, $orders(), 'and the orders');
        self::assertSame('draft', get_post_status((int) $this->other), 'the shop\'s own product was not published');
    }

    public function testTheProbePassesOnAShopWithNoProductsCustomersOrdersOrStockrooms(): void
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $this->install(['main.ocs' => $this->probe()]);
        $container = self::kernel()->container();
        $tests = $container->get(PackageTests::class);
        self::assertInstanceOf(PackageTests::class, $tests);
        // The connection the tests' own transactions are begun on, so that they nest in this one.
        $store = new \ReflectionProperty(PackageTests::class, 'objects')->getValue($tests);
        $entities = \is_object($store) ? new \ReflectionProperty($store, 'entityManager')->getValue($store) : null;
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $connection = $entities->getConnection();
        $p = $wpdb->prefix;
        $customers = "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = '{$p}capabilities' AND meta_value LIKE '%orillacart_customer%'";
        $records = static fn(): int => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}orillacart_orders") + (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}orillacart_products") + (int) $wpdb->get_var($customers);
        // An empty shop for the length of the test, in a transaction its own tests' transactions nest in.
        $connection->beginTransaction();
        try {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['orillacart_orders', 'orillacart_products', 'orillacart_stockrooms', 'orillacart_stock_levels'] as $table) {
                $connection->executeStatement("DELETE FROM {$p}{$table}");
            }
            $connection->executeStatement("DELETE FROM {$wpdb->posts} WHERE post_type = 'orillacart_product'");
            $connection->executeStatement("DELETE u, m FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id = u.ID WHERE u.ID IN (SELECT user_id FROM (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '{$p}capabilities' AND meta_value LIKE '%orillacart_customer%') c)");
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            wp_cache_flush();
            self::assertSame(0, $records(), 'no orders, products or customers');

            $results = $tests->run('probe');
            $made = $results[0];
            self::assertTrue($made['ok'], (string) wp_json_encode($made));
            self::assertSame(0, $records(), 'and it left the shop empty');
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $connection->rollBack();
            wp_cache_flush();
        }
    }

    public function testFilesSentToTestAndDebugLieOverTheSavedOnes(): void
    {
        $this->install(['main.ocs' => $this->probe(), 'more.ocs' => "test \"saved too\" fn() { assert true }\n"]);

        // One file more: the manifest and its needs are still the saved main.ocs's.
        $tests = $this->tests(['extra.ocs' => "test \"sent\" fn() { assert len(shop.customers.search({ limit: 1 })) <= 1 }\n"]);
        self::assertSame(['makes what it needs on any shop', 'what is not the shop\'s is refused', 'the stock modes it lists', 'saved too', 'sent'], array_column($tests, 'name'));
        self::assertSame([true, true, true, true, true], array_column($tests, 'ok'), (string) wp_json_encode($tests));

        // Null removes a saved file; a path sent replaces its file.
        $tests = $this->tests(['more.ocs' => null, 'main.ocs' => "extension \"probe\" { name: \"Probe\" }\ntest \"replaced\" fn() { assert true }\n"]);
        self::assertSame(['replaced'], array_column($tests, 'name'));

        $debugged = $this->request('POST', '/extensions/probe/debug', ['test' => 'sent', 'files' => ['extra.ocs' => "test \"sent\" fn() { assert true }\n"]], nonce: true);
        self::assertSame(200, $debugged->get_status(), (string) wp_json_encode($debugged->get_data()));
        self::assertStringNotContainsString('does not compile', (string) wp_json_encode($debugged->get_data()));
    }

    public function testAnImportRowTakesEachStockModeItLists(): void
    {
        $rows = self::kernel()->container()->get(ProductRows::class);
        self::assertInstanceOf(ProductRows::class, $rows);
        foreach (['tracked', 'always_available', 'unavailable'] as $mode) {
            [$added, , $id] = $rows->import(['name' => 'Row ' . $mode, 'price' => '2.00', 'stock_mode' => $mode]);
            try {
                self::assertTrue($added, $mode);
            } finally {
                wp_delete_post($id, true);
            }
        }
    }

    private function probe(): string
    {
        return str_replace(['ADDRESS', 'OTHER'], [self::ADDRESS, (string) $this->other], self::PROBE);
    }

    /** @param array<string, string> $files */
    private function install(array $files): void
    {
        $installed = $this->request('POST', '/extensions', ['id' => 'probe', 'name' => 'Probe', 'files' => $files], nonce: true);
        self::assertSame(201, $installed->get_status(), (string) wp_json_encode($installed->get_data()));
        // Granted only what it declares: nothing it makes in its tests asks for more.
        $enabled = $this->request('POST', '/extensions/probe/enable', ['grant' => ['customers:read', 'orders:read']], nonce: true);
        self::assertSame(200, $enabled->get_status(), (string) wp_json_encode($enabled->get_data()));
    }

    /**
     * @param array<string, string|null>|null $files
     *
     * @return list<array{name: string, ok: bool, effects: list<array{function: string}>}>
     */
    private function tests(?array $files = null): array
    {
        $answer = $this->request('POST', '/extensions/probe/test', $files === null ? [] : ['files' => $files], nonce: true);
        self::assertSame(200, $answer->get_status(), (string) wp_json_encode($answer->get_data()));
        $data = $answer->get_data();
        self::assertIsArray($data);
        $tests = \is_array($data['data'] ?? null) ? ($data['data']['tests'] ?? []) : ($data['tests'] ?? []);
        self::assertIsArray($tests);

        /** @var list<array{name: string, ok: bool, effects: list<array{function: string}>}> $tests */
        return $tests;
    }
}
