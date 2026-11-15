<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;

/**
 * Claiming a visitor's cart against the database: one statement decides who takes it, and a request that comes
 * second reads the cart as the first left it, not as it loaded it before.
 */
final class CartClaimTest extends RestTestCase
{
    /** @var list<int> */
    private array $posts = [];
    private int $customer;
    private int $lamp;
    private int $vase;

    protected function setUp(): void
    {
        parent::setUp();
        $wpdb = $this->db();
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_cart_lines");
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_carts");
        $this->actAs('administrator');
        $stockroom = $this->data($this->request('POST', '/stockrooms', ['name' => 'Claims ' . uniqid()], nonce: true))['id'] ?? null;
        self::assertIsInt($stockroom);
        $this->lamp = $this->product('Lamp', $stockroom);
        $this->vase = $this->product('Vase', $stockroom);
        $this->customer = $this->actAs('subscriber');
    }

    protected function tearDown(): void
    {
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        $this->posts = [];
        parent::tearDown();
    }

    public function testClaimingJoinsTheAccountsCartOnceAndLeavesOneCart(): void
    {
        $carts = $this->carts();
        $carts->add(new CartOwner(null, $this->customer), new AddToCart($this->vase, quantity: 2));
        $token = $carts->add(new CartOwner(null), new AddToCart($this->lamp))->newToken;
        self::assertIsString($token);
        $owner = new CartOwner($token, $this->customer);
        self::assertTrue($carts->claimJoins($owner));

        $claimed = $carts->claim($owner);

        self::assertIsString($claimed);
        self::assertNotSame($token, $claimed);
        self::assertCount(1, $this->cartIds(), 'the visitor\'s cart went into the account\'s');
        self::assertNull($carts->claim($owner), 'the old token claims nothing more');
        self::assertNull($carts->claim(new CartOwner($claimed, $this->customer)));
        self::assertSame([$this->lamp => 1, $this->vase => 2], $this->quantities($carts, new CartOwner($claimed, $this->customer)));
    }

    public function testARequestThatLostTheClaimReadsTheCartAsTheOtherLeftIt(): void
    {
        $carts = $this->carts();
        $token = $carts->add(new CartOwner(null), new AddToCart($this->lamp))->newToken;
        self::assertIsString($token);
        $owner = new CartOwner($token, $this->customer);
        // This request has read the cart, a visitor's still.
        self::assertSame([$this->lamp => 1], $this->quantities($carts, $owner));
        // Another request takes it meanwhile, and its customer changes it.
        $wpdb = $this->db();
        $id = $this->cartIds()[0];
        $wpdb->query("UPDATE {$wpdb->prefix}orillacart_carts SET customer_id = {$this->customer} WHERE id = {$id}");
        $wpdb->query("UPDATE {$wpdb->prefix}orillacart_cart_lines SET quantity = 3 WHERE cart_id = {$id}");
        $wpdb->query("UPDATE {$wpdb->prefix}orillacart_carts SET updated_at = updated_at + INTERVAL 1 MINUTE WHERE id = {$id}");

        self::assertNull($carts->claim($owner), 'the database gave it to the other request');

        self::assertSame([$this->lamp => 3], $this->quantities($carts, $owner), 'read anew, not as this request loaded it');
        $carts->add($owner, new AddToCart($this->lamp));
        self::assertSame([$this->lamp => 4], $this->quantities($carts, $owner));
        self::assertSame([$id], $this->cartIds());
        self::assertSame((string) $this->customer, $wpdb->get_var("SELECT customer_id FROM {$wpdb->prefix}orillacart_carts WHERE id = {$id}"));
    }

    /**
     * @return array<int, int> quantity by product
     */
    private function quantities(CartService $carts, CartOwner $owner): array
    {
        $quantities = [];
        foreach ($carts->cart($owner)->cart->lines as $line) {
            $quantities[$line->productId] = ($quantities[$line->productId] ?? 0) + $line->quantity;
        }
        ksort($quantities);

        return $quantities;
    }

    /**
     * @return list<int>
     */
    private function cartIds(): array
    {
        return array_map(intval(...), $this->db()->get_col("SELECT id FROM {$this->db()->prefix}orillacart_carts ORDER BY id"));
    }

    private function carts(): CartService
    {
        $carts = self::kernel()->container()->get(CartService::class);
        self::assertInstanceOf(CartService::class, $carts);

        return $carts;
    }

    private function product(string $title, int $stockroom): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $title, 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->posts[] = $id;
        self::assertSame(200, $this->request('PUT', "/products/{$id}", ['price' => '10.00'], nonce: true)->get_status());
        $this->setStock('product', $id, [$stockroom => 9]);

        return $id;
    }

    private function db(): \wpdb
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);

        return $wpdb;
    }
}
