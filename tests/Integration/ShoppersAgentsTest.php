<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Agents\Application\AgentCheckouts;
use OrillaCart\Agents\Application\Ucp;
use OrillaCart\Agents\Application\UcpService;
use OrillaCart\Agents\Infrastructure\WordPress\OptionAgentCommerceSwitch;
use OrillaCart\Agents\Infrastructure\WordPress\WordPressAgentEndpoints;
use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Jobs\Application\JobRunner;

/**
 * Design 75: a shopper's agent buys through UCP. It reads the shop's profile, finds a product as shoppers find it,
 * makes a checkout session of one of the shop's carts, sets the buyer, and is handed a link for the buyer to pay at
 * the shop's checkout; the order placed from the cart completes the session. Retries with the same key get the same
 * answer; the agent's profile decides the capabilities; nothing answers while the owner has not switched it on.
 */
final class ShoppersAgentsTest extends RestTestCase
{
    private const string AGENT = 'https://agent.example/profile.json';
    private const string OLD_AGENT = 'https://old-agent.example/profile.json';

    /** @var list<int> */
    private array $posts = [];
    private int $product;
    /** @var (\Closure(mixed, array<mixed>, string): mixed)|null */
    private ?\Closure $profiles = null;
    private mixed $wasOn = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        $this->wasOn = get_option(OptionAgentCommerceSwitch::OPTION);
        update_option(OptionAgentCommerceSwitch::OPTION, '1');
        $post = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Agent guide ' . uniqid(), 'post_status' => 'publish']);
        self::assertIsInt($post);
        $this->posts[] = $post;
        $this->product = $post;
        self::assertSame(200, $this->request('PUT', "/products/{$post}", ['price' => '12.5', 'type' => 'virtual', 'stock_mode' => 'always_available'], nonce: true)->get_status());
        $runner = self::kernel()->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        while ($runner->work(['search'], 100, 'test')->count() > 0);
        $this->profiles = static function (mixed $pre, array $args, string $url): mixed {
            $version = match ($url) {
                self::AGENT => Ucp::VERSION,
                self::OLD_AGENT => '2026-01-11',
                default => null,
            };
            if ($version === null) {
                return $pre;
            }
            $capabilities = [];
            foreach (array_keys(Ucp::CAPABILITIES) as $name) {
                $capabilities[$name] = [['version' => $version]];
            }

            return ['headers' => [], 'body' => (string) wp_json_encode(['ucp' => ['version' => $version, 'capabilities' => $capabilities]]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $this->profiles, 10, 3);
        delete_transient('orillacart_ucp_agent_' . md5(self::AGENT));
        delete_transient('orillacart_ucp_agent_' . md5(self::OLD_AGENT));
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        if (\is_callable($this->profiles)) {
            remove_filter('pre_http_request', $this->profiles, 10);
        }
        $this->wasOn === false ? delete_option(OptionAgentCommerceSwitch::OPTION) : update_option(OptionAgentCommerceSwitch::OPTION, $this->wasOn);
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        parent::tearDown();
    }

    public function testAnAgentFindsAProductAndHandsTheBuyerACheckoutOfIt(): void
    {
        $profile = json_decode((string) wp_json_encode($this->ucp()->profile()), true);
        self::assertIsArray($profile);
        self::assertSame(Ucp::VERSION, self::at($profile, 'ucp', 'version'));
        self::assertSame(['rest', 'mcp'], array_column(self::list(self::at($profile, 'ucp', 'services', Ucp::SERVICE)), 'transport'));
        self::assertArrayHasKey(Ucp::CHECKOUT, self::list(self::at($profile, 'ucp', 'capabilities')));

        $found = $this->ucpRequest('POST', '/ucp/catalog/search', ['query' => 'Agent guide', 'pagination' => ['limit' => 5]]);
        self::assertSame(200, $found->get_status(), (string) wp_json_encode($found->get_data()));
        $products = $this->body($found)['products'] ?? null;
        self::assertIsArray($products);
        $product = array_values(array_filter($products, fn(mixed $p): bool => \is_array($p) && ($p['id'] ?? null) === 'prod_' . $this->product))[0] ?? null;
        self::assertIsArray($product, 'the shop\'s own search finds it');
        self::assertSame(1250, self::at($product, 'price_range', 'min', 'amount'), 'in minor units');
        $variant = self::at($product, 'variants', 0);
        self::assertIsArray($variant);
        self::assertSame('prod_' . $this->product, $variant['id'] ?? null, 'a simple product is its own variant');

        $create = ['line_items' => [['item' => ['id' => $variant['id']], 'quantity' => 2]]];
        $made = $this->ucpRequest('POST', '/ucp/checkout-sessions', $create, 'key-' . uniqid());
        self::assertSame(201, $made->get_status(), (string) wp_json_encode($made->get_data()));
        $session = $this->body($made);
        self::assertSame('incomplete', $session['status'] ?? null);
        self::assertSame(['version' => Ucp::VERSION, 'status' => 'success'], array_intersect_key(\is_array($session['ucp'] ?? null) ? $session['ucp'] : [], ['version' => 1, 'status' => 1]));
        self::assertContains('$.buyer.email', array_column(\is_array($session['messages'] ?? null) ? $session['messages'] : [], 'path'));
        $id = $session['id'] ?? null;
        self::assertIsString($id);
        $lines = $session['line_items'] ?? null;
        self::assertIsArray($lines);
        self::assertSame(2, self::at($lines, 0, 'quantity'));
        $total = self::at(array_values(array_filter(self::list($session['totals'] ?? null), static fn(mixed $t): bool => \is_array($t) && ($t['type'] ?? null) === 'total')), 0, 'amount');
        self::assertIsInt($total);
        self::assertGreaterThanOrEqual(2500, $total);

        $key = 'key-' . uniqid();
        $buyer = ['line_items' => $create['line_items'], 'buyer' => ['email' => 'shopper@example.com', 'first_name' => 'Ada', 'last_name' => 'Lovelace']];
        $ready = $this->ucpRequest('PUT', '/ucp/checkout-sessions/' . $id, $buyer, $key);
        self::assertSame(200, $ready->get_status(), (string) wp_json_encode($ready->get_data()));
        $ready = $this->body($ready);
        self::assertSame('requires_escalation', $ready['status'] ?? null, 'all set but the payment, which the buyer makes');
        self::assertContains('requires_buyer_input', array_column(\is_array($ready['messages'] ?? null) ? $ready['messages'] : [], 'severity'));
        $continue = $ready['continue_url'] ?? null;
        self::assertIsString($continue);
        parse_str((string) wp_parse_url($continue, \PHP_URL_QUERY), $query);
        self::assertSame($id, $query[WordPressAgentEndpoints::SESSION] ?? null);

        $again = $this->ucpRequest('PUT', '/ucp/checkout-sessions/' . $id, $buyer, $key);
        self::assertSame($ready, $this->body($again), 'the same key and body: the same answer');
        self::assertSame(409, $this->ucpRequest('PUT', '/ucp/checkout-sessions/' . $id, ['line_items' => $create['line_items']], $key)->get_status(), 'the same key, another body');

        // The link gives the buyer the cart, whose checkout starts with what the agent gave.
        $checkouts = self::kernel()->container()->get(AgentCheckouts::class);
        \assert($checkouts instanceof AgentCheckouts);
        $token = $checkouts->handover($id);
        self::assertIsString($token);
        self::assertSame(['email' => 'shopper@example.com', 'billing' => ['first_name' => 'Ada', 'last_name' => 'Lovelace']], $checkouts->given($token));
        $carts = self::kernel()->container()->get(CartService::class);
        \assert($carts instanceof CartService);
        $cartId = $carts->contents(new CartOwner($token))?->cartId;
        self::assertIsInt($cartId);

        // Completing is the buyer's: the agent is told where.
        $complete = $this->body($this->ucpRequest('POST', '/ucp/checkout-sessions/' . $id . '/complete', [], 'key-' . uniqid()));
        self::assertSame('requires_escalation', $complete['status'] ?? null);

        // The buyer places the order from the cart, at the shop's checkout.
        $carts->placing($cartId, 4242);
        $done = $this->body($this->ucpRequest('GET', '/ucp/checkout-sessions/' . $id));
        self::assertSame('completed', $done['status'] ?? null);
        self::assertSame('4242', self::at($done, 'order', 'id'));
        self::assertArrayNotHasKey('continue_url', $done);
    }

    public function testAnAgentCancelsAndTheCapabilitiesAreItsProfilesAndOff(): void
    {
        $made = $this->body($this->ucpRequest('POST', '/ucp/checkout-sessions', ['line_items' => [['item' => ['id' => 'prod_' . $this->product], 'quantity' => 1]]], 'key-' . uniqid()));
        $id = $made['id'] ?? null;
        self::assertIsString($id);
        $canceled = $this->body($this->ucpRequest('POST', '/ucp/checkout-sessions/' . $id . '/cancel', [], 'key-' . uniqid()));
        self::assertSame('canceled', $canceled['status'] ?? null);
        self::assertSame([], $canceled['line_items'] ?? null, 'its cart emptied');

        $unknown = $this->body($this->ucpRequest('POST', '/ucp/checkout-sessions', ['line_items' => [['item' => ['id' => 'prod_999999999'], 'quantity' => 1]]], 'key-' . uniqid()));
        self::assertSame('error', self::at($unknown, 'ucp', 'status'), 'nothing could be put in a cart');
        self::assertSame('item_unavailable', self::at($unknown, 'messages', 0, 'code'));

        $old = $this->ucpRequest('POST', '/ucp/catalog/search', ['query' => 'Agent guide'], null, self::OLD_AGENT);
        self::assertSame(200, $old->get_status());
        self::assertSame('capabilities_incompatible', self::at($this->body($old), 'messages', 0, 'code'));
        self::assertSame(424, $this->ucpRequest('POST', '/ucp/catalog/search', [], null, 'https://nowhere.invalid/profile.json')->get_status());
        self::assertSame(400, $this->ucpRequest('POST', '/ucp/checkout-sessions', ['line_items' => []])->get_status(), 'a change names its idempotency key');

        update_option(OptionAgentCommerceSwitch::OPTION, '0');
        self::assertSame(404, $this->ucpRequest('POST', '/ucp/catalog/search', ['query' => 'x'])->get_status(), 'off until the owner switches it on');
    }

    public function testTheSameOperationsAreMcpTools(): void
    {
        $listed = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $tools = self::at($listed, 'result', 'tools');
        self::assertIsArray($tools);
        self::assertContains('create_checkout', array_column($tools, 'name'));

        $called = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'create_checkout', 'arguments' => ['meta' => ['ucp-agent' => ['profile' => self::AGENT]], 'checkout' => ['line_items' => [['item' => ['id' => 'prod_' . $this->product], 'quantity' => 1]], 'buyer' => ['email' => 'shopper@example.com']]]]]);
        $session = self::at($called, 'result', 'structuredContent');
        self::assertIsArray($session, (string) wp_json_encode($called));
        self::assertSame('requires_escalation', $session['status'] ?? null);

        $refused = $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'cancel_checkout', 'arguments' => ['meta' => ['ucp-agent' => ['profile' => self::AGENT]], 'id' => $session['id'] ?? '']]]);
        self::assertSame('idempotency_key_required', self::at($refused, 'error', 'data', 'code'), 'cancelling names its key');
    }

    /** @return array<string, mixed> */
    private function body(\WP_REST_Response $response): array
    {
        $body = json_decode((string) wp_json_encode($response->get_data()), true);
        self::assertIsArray($body);
        $named = [];
        foreach ($body as $key => $value) {
            $named[(string) $key] = $value;
        }

        return $named;
    }

    /** What lies at the path in a decoded answer, or null. */
    private static function at(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $step) {
            $value = \is_array($value) ? ($value[$step] ?? null) : null;
        }

        return $value;
    }

    /** @return array<mixed> */
    private static function list(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    private function ucp(): UcpService
    {
        $ucp = self::kernel()->container()->get(UcpService::class);
        \assert($ucp instanceof UcpService);

        return $ucp;
    }

    /** @param array<string, mixed> $body */
    private function ucpRequest(string $method, string $route, array $body = [], ?string $key = null, string $agent = self::AGENT): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, self::NAMESPACE . $route);
        $request->set_header('UCP-Agent', 'profile="' . $agent . '"');
        $request->set_header('Request-Id', 'req-' . uniqid());
        if ($key !== null) {
            $request->set_header('Idempotency-Key', $key);
        }
        if ($method !== 'GET') {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body === [] ? new \stdClass() : $body));
        }

        return rest_do_request($request);
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function mcp(array $message): array
    {
        $request = new \WP_REST_Request('POST', self::NAMESPACE . '/ucp/mcp');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($message));
        return $this->body(rest_do_request($request));
    }
}
