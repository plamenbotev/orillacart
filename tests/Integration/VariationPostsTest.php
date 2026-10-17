<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\VariationPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\ProductGallery;

/**
 * Variations as WordPress keeps them: a post of their own under the product's, which holds their name,
 * description and pictures, and goes when the variation goes, and the other way round.
 */
final class VariationPostsTest extends RestTestCase
{
    private int $product;
    /** @var array{int, int} */
    private array $options;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Hoodie', 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->product = $id;
        $saved = $this->data($this->request('PUT', "/products/{$id}", ['price' => '40', 'attributes' => [
            ['name' => 'Size', 'options' => [['name' => 'S'], ['name' => 'M']]],
        ]], nonce: true));
        self::assertIsArray($saved['attributes']);
        self::assertIsArray($saved['attributes'][0]);
        self::assertIsArray($saved['attributes'][0]['options']);
        $ids = array_column($saved['attributes'][0]['options'], 'id');
        self::assertIsInt($ids[0]);
        self::assertIsInt($ids[1]);
        $this->options = [$ids[0], $ids[1]];
    }

    protected function tearDown(): void
    {
        wp_delete_post($this->product, true);
        parent::tearDown();
    }

    public function testAVariationIsAChildPostTheShopEditsThroughTheRestApi(): void
    {
        $variation = $this->variation($this->options[1], ['weight' => '0.9']);

        $post = get_post($variation);
        self::assertInstanceOf(\WP_Post::class, $post);
        self::assertSame([VariationPostType::NAME, $this->product, 'Hoodie – M', 'private'], [$post->post_type, $post->post_parent, $post->post_title, $post->post_status]);

        $edited = $this->rest('POST', "/wp/v2/orillacart-variations/{$variation}", ['excerpt' => 'Brushed cotton.', 'meta' => [ProductGallery::META => [7, 5]]], true);
        self::assertSame(200, $edited->get_status(), (string) wp_json_encode($edited->get_data()));
        self::assertSame('Brushed cotton.', get_post_field('post_excerpt', $variation));
        self::assertSame([7, 5], ProductGallery::of($variation), 'in the order chosen');
        self::assertSame(400, $this->rest('POST', "/wp/v2/orillacart-variations/{$variation}", ['meta' => [ProductGallery::META => [0]]], true)->get_status(), 'only media ids');

        // Editors who cannot run the shop, and visitors, neither change nor read it.
        $this->actAs('editor');
        self::assertSame(403, $this->rest('POST', "/wp/v2/orillacart-variations/{$variation}", ['excerpt' => 'Mine.'], true)->get_status());
        wp_set_current_user(0);
        self::assertSame(401, $this->rest('GET', "/wp/v2/orillacart-variations/{$variation}")->get_status(), 'not even for a published product: its variations would tell about draft ones');
    }

    public function testDeletingEitherSideTakesTheOtherWithIt(): void
    {
        $small = $this->variation($this->options[0]);
        $medium = $this->variation($this->options[1]);

        // The shop removes a variation: its post goes.
        self::assertSame(204, $this->request('DELETE', "/products/{$this->product}/variations/{$small}", nonce: true)->get_status());
        self::assertNull(get_post($small));

        // The post is deleted in WordPress: the variation goes.
        wp_delete_post($medium, true);
        self::assertSame([], $this->data($this->request('GET', "/products/{$this->product}"))['variations']);

        // The product is deleted: its variations' posts go too.
        $large = $this->variation($this->options[1]);
        wp_delete_post($this->product, true);
        self::assertNull(get_post($large));
    }

    /**
     * @param array<string, mixed> $details
     */
    private function variation(int $option, array $details = []): int
    {
        $made = $this->request('POST', "/products/{$this->product}/variations", ['option_ids' => [$option], 'price' => '42'] + $details, nonce: true);
        self::assertSame(201, $made->get_status(), (string) wp_json_encode($made->get_data()));
        $id = $this->data($made)['id'];
        self::assertIsInt($id);

        return $id;
    }

    /**
     * A call to WordPress's own REST API, outside the plugin's namespace.
     *
     * @param array<string, mixed> $body
     */
    private function rest(string $method, string $route, array $body = [], bool $nonce = false): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, $route);
        if ($body !== []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }
        if ($nonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }

        return rest_do_request($request);
    }
}
