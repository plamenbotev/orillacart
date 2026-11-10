<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;

/**
 * Design 62: records made to try the shop out are never emailed, take no invoice number, and go in one call;
 * an address no mail can reach is never written to.
 */
final class TestDataRestTest extends RestTestCase
{
    /** @var list<int> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        $this->request('DELETE', '/test-data', nonce: true);
    }

    protected function tearDown(): void
    {
        foreach ($this->posts as $id) {
            wp_delete_post($id, true);
        }
        parent::tearDown();
    }

    public function testATestCustomersOrderIsNeverEmailedTakesNoInvoiceAndGoesWithTheTestData(): void
    {
        $mug = $this->product();
        $address = 'tester.' . bin2hex(random_bytes(3)) . '@gmail.com';
        $made = $this->request('POST', '/customers', ['email' => $address, 'first_name' => 'Test', 'last_name' => 'Buyer', 'test' => true], nonce: true);
        self::assertSame(201, $made->get_status(), (string) wp_json_encode($made->get_data()));
        $customer = $this->data($made);
        self::assertTrue($customer['test']);
        self::assertIsInt($customer['id']);
        $customerId = $customer['id'];

        $billing = ['first_name' => 'Test', 'last_name' => 'Buyer', 'street' => '1 Main St', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG', 'phone' => '+359 2 000 0000'];
        $placed = $this->request('POST', '/orders', ['email' => $address, 'customer_id' => $customerId, 'billing' => $billing, 'items' => [['product_id' => $mug, 'quantity' => 1]], 'paid' => true, 'email_customer' => true], nonce: true);
        self::assertSame(201, $placed->get_status(), (string) wp_json_encode($placed->get_data()));
        $order = $this->data($placed);
        self::assertTrue($order['test'], 'a test customer\'s order is a test order');
        self::assertIsInt($order['number']);
        $number = $order['number'];
        self::assertSame([], array_filter($this->sentMail(), static fn(array $mail): bool => str_contains($mail['to'], $address)), 'never emailed, though asked');
        self::assertSame([], $this->data($this->request('GET', '/orders/' . $number . '/invoices'))['invoices'] ?? [], 'no number of the series');

        self::assertSame(['orders' => 1, 'customers' => 1], $this->data($this->request('GET', '/test-data')));
        $listed = $this->data($this->request('GET', '/orders?test=yes'))['orders'];
        self::assertIsArray($listed);
        self::assertSame([$number], array_column($listed, 'number'));

        self::assertSame(['orders' => 1, 'customers' => 1], $this->data($this->request('DELETE', '/test-data', nonce: true)));
        self::assertSame(404, $this->request('GET', '/orders/' . $number)->get_status());
        self::assertSame(404, $this->request('GET', '/customers/' . $customerId)->get_status());
    }

    public function testNoEmailGoesToAnAddressReservedForExamples(): void
    {
        // The rule as a live site has it: the base class lets example.com through to its catcher.
        remove_filter('orillacart_undeliverable_address', '__return_false');
        try {
            $made = $this->request('POST', '/customers', ['email' => 'nobody.' . bin2hex(random_bytes(3)) . '@example.com', 'first_name' => 'No', 'last_name' => 'Body'], nonce: true);
            self::assertSame(201, $made->get_status());
            $customer = $this->data($made);
            self::assertIsInt($customer['id']);
            $this->request('POST', '/customers/' . $customer['id'] . '/welcome', nonce: true);
            self::assertSame([], array_filter($this->sentMail(), static fn(array $mail): bool => str_contains($mail['to'], '@example.com')));
        } finally {
            add_filter('orillacart_undeliverable_address', '__return_false');
        }
    }

    private function product(): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Test mug', 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->posts[] = $id;
        $saved = $this->request('PUT', "/products/{$id}", ['price' => '10', 'type' => 'virtual', 'stock_mode' => 'always_available'], nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));

        return $id;
    }
}
