<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;

/**
 * Design 61: what an extension reads of the shop and does in it beyond marking: a product as shoppers see it, a
 * customer, an email in the shop's layout, an order placed as the shop places one by hand.
 */
final class ScriptedShopTest extends RestTestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "shopper" { name: "Shopper", needs: { products: read, customers: read, orders: create, email: send } }

        fn product(id) { return shop.products.find(id) }
        fn price_text(id) { let p = shop.products.find(id)
          return [p.price|number(2), p.price|amount] }
        fn customer(id) { return shop.customers.find(id) }
        fn mail(to) { return shop.email.send(to, "Your quote is ready", "<p>Quote <b>#7</b> waits for you.</p><script>alert(1)</script>") }
        fn too_many(to) {
          for i in 1..6 { shop.email.send(to, "Hello " ~ i, "<p>Hi</p>") }
        }
        fn order(product, customer) {
          return shop.orders.create({
            email: "grace@example.com", customer_id: customer,
            billing: { first_name: "Grace", last_name: "Hopper", street: "1 Navy Way", city: "Arlington", postcode: "22201", country: "US", state: "VA", phone: "555" },
            items: [{ product_id: product, quantity: 2, unit_price: "7.25" }],
            note: "From quote #7",
          })
        }
        fn refused(product) {
          try {
            shop.orders.create({ email: "x@example.com", billing: { first_name: "X", last_name: "Y", street: "1", city: "C", postcode: "22201", country: "US", state: "VA", phone: "1" }, items: [{ product_id: 999999, quantity: 1 }] })
          } catch e { return e.kind }
        }
        OCS;

    /** @var list<int> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        $this->request('DELETE', '/extensions/shopper', nonce: true);
        $this->request('POST', '/extensions', ['id' => 'shopper', 'name' => 'Shopper', 'files' => ['main.ocs' => self::SCRIPT]], nonce: true);
        $enabled = $this->request('POST', '/extensions/shopper/enable', ['grant' => ['products:read', 'customers:read', 'orders:create', 'email:send']], nonce: true);
        self::assertSame(200, $enabled->get_status(), (string) wp_json_encode($enabled->get_data()));
    }

    protected function tearDown(): void
    {
        $this->request('DELETE', '/extensions/shopper', nonce: true);
        foreach ($this->posts as $id) {
            wp_delete_post($id, true);
        }
        parent::tearDown();
    }

    public function testAProductAndACustomerAreReadAsTheShopShowsThem(): void
    {
        $mug = $this->product('Trade mug', ['price' => '12.5', 'type' => 'virtual', 'sku' => 'MUG-1', 'stock_mode' => 'always_available']);
        $product = $this->assoc($this->call('product', [$mug]));
        self::assertSame([$mug, 'Trade mug', 'virtual', 'MUG-1', '12.5000', null, true], [$product['id'], $product['name'], $product['type'], $product['sku'], $product['price'], $product['regular_price'], $product['for_sale']]);
        self::assertSame(['12.50', '12.50'], $this->call('price_text', [$mug]), 'money formats as a number, and as an amount');
        self::assertNull($this->call('product', [999999]));

        $made = $this->customer('grace.shop');
        $customer = $this->assoc($this->call('customer', [$made['id']]));
        self::assertSame([$made['email'], 'Grace', 'Hopper', 0], [$customer['email'], $customer['first_name'], $customer['last_name'], $customer['orders']]);
        self::assertArrayNotHasKey('password', $customer);
        self::assertNull($this->call('customer', [999999]));
    }

    public function testAnEmailGoesInTheShopsLayoutCleanedAndFiveARun(): void
    {
        self::assertTrue($this->call('mail', ['buyer@example.com']));
        $mail = array_values(array_filter($this->sentMail(), static fn(array $m): bool => $m['subject'] === 'Your quote is ready'));
        self::assertCount(1, $mail);
        self::assertSame('buyer@example.com', $mail[0]['to']);
        self::assertStringContainsString('Quote <b>#7</b> waits for you.', $mail[0]['message']);
        self::assertStringNotContainsString('<script', $mail[0]['message'], 'cleaned');
        self::assertStringContainsString('<h1', $mail[0]['message'], 'in the shop\'s layout');

        $over = $this->data($this->request('POST', '/extensions/shopper/run', ['function' => 'too_many', 'arguments' => ['buyer@example.com']], nonce: true));
        self::assertFalse($over['ok']);
        self::assertStringContainsString('more than 5 emails', self::text($over['stopped'] ?? ''));
    }

    public function testAnOrderIsPlacedAsTheShopPlacesOneByHand(): void
    {
        $mug = $this->product('Quote mug', ['price' => '12.5', 'type' => 'virtual', 'stock_mode' => 'always_available']);
        $made = $this->customer('grace.order');
        $order = $this->assoc($this->call('order', [$mug, $made['id']]));
        self::assertSame('on_hold', $order['status'], 'waits for its payment');
        self::assertSame($made['id'], $order['customer_id']);
        $lines = $order['lines'];
        self::assertIsArray($lines);
        $line = $this->assoc($lines[0] ?? null);
        self::assertSame([$mug, 2, '7.2500'], [$line['product_id'], $line['quantity'], $line['unit_price']], 'at the price agreed');
        self::assertSame('From quote #7', $order['note']);

        self::assertSame('refused', $this->call('refused', [$mug]), 'what the shop refuses is a refusal');
    }

    /** @param list<mixed> $arguments */
    private function call(string $function, array $arguments = []): mixed
    {
        $answer = $this->data($this->request('POST', '/extensions/shopper/run', ['function' => $function, 'arguments' => $arguments], nonce: true));
        self::assertTrue($answer['ok'] ?? false, (string) wp_json_encode($answer));

        return $answer['result'];
    }

    /** @return array<string, mixed> */
    private function customer(string $name): array
    {
        $made = $this->request('POST', '/customers', ['email' => $name . '.' . bin2hex(random_bytes(3)) . '@example.com', 'first_name' => 'Grace', 'last_name' => 'Hopper'], nonce: true);
        self::assertSame(201, $made->get_status(), (string) wp_json_encode($made->get_data()));

        return $this->assoc($this->data($made));
    }

    /** @param array<string, mixed> $details */
    private function product(string $name, array $details): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $name, 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->posts[] = $id;
        $saved = $this->request('PUT', "/products/{$id}", $details, nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));

        return $id;
    }

    /** @return array<string, mixed> */
    private function assoc(mixed $value): array
    {
        self::assertIsArray($value);
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
