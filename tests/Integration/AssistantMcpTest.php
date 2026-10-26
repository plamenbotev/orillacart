<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * The assistant's MCP endpoint on a real site: a key connects, the shop's routes are its tools, a call runs the
 * route as the key's manager would, and the change log says it came from the assistant.
 */
final class AssistantMcpTest extends RestTestCase
{
    /** @var array<mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_api_keys");
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        unset($_SERVER['HTTP_AUTHORIZATION']);
        // The key the last request came with is kept per PHP process: a request without one forgets it.
        apply_filters('determine_current_user', 0);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testAKeyListsTheToolsAndACallRunsItsRoute(): void
    {
        $this->connect('read_write');

        $tools = $this->mcp('tools/list');
        $names = self::names(self::at($tools, 'result', 'tools'));
        self::assertContains('create_tax_group', $names);
        self::assertContains('list_orders', $names);
        self::assertNotContains('create_api_key', $names, 'keys are made by a manager logged in');
        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names, 'a fixed order, for clients to cache');

        $made = $this->mcp('tools/call', ['name' => 'create_tax_group', 'arguments' => ['name' => 'Books']]);
        self::assertFalse(self::at($made, 'result', 'isError'));
        self::assertSame('Books', self::at($made, 'result', 'structuredContent', 'name'));

        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $logged = $wpdb->get_row("SELECT route, via, key_name FROM {$wpdb->prefix}orillacart_change_log ORDER BY id DESC LIMIT 1", ARRAY_A);
        self::assertSame(['route' => 'POST /tax/groups', 'via' => 'assistant', 'key_name' => 'Assistant'], $logged);

        $refused = $this->mcp('tools/call', ['name' => 'create_tax_group', 'arguments' => ['name' => '']]);
        self::assertTrue(self::at($refused, 'result', 'isError'));
        self::assertStringContainsString('"field": "name"', self::textAt($refused, 'result', 'content', 0, 'text'));

        $shown = $this->mcp('tools/call', ['name' => 'show_tax_group', 'arguments' => ['id' => self::at($made, 'result', 'structuredContent', 'id')]]);
        self::assertFalse(self::at($shown, 'result', 'isError'));
        self::assertSame([], self::at($shown, 'result', 'structuredContent', 'rates'));
    }

    public function testTheSetupIsAToolPerStepThenThePlan(): void
    {
        $this->connect('read_write');
        delete_option('orillacart_setup_answers');

        $names = self::names(self::at($this->mcp('tools/list'), 'result', 'tools'));
        self::assertContains('setup_answer_business', $names);
        self::assertContains('setup_answer_selling', $names);
        self::assertNotContains('save_setup_answers', $names, 'each step has its own tool instead');

        $refused = $this->mcp('tools/call', ['name' => 'setup_answer_selling', 'arguments' => ['customers' => 'everyone', 'regions' => 'eu']]);
        self::assertTrue(self::at($refused, 'result', 'isError'));

        $saved = $this->mcp('tools/call', ['name' => 'setup_answer_selling', 'arguments' => ['customers' => 'both', 'regions' => 'eu']]);
        self::assertFalse(self::at($saved, 'result', 'isError'));
        self::assertSame(['customers' => 'both', 'regions' => 'eu'], self::at($saved, 'result', 'structuredContent', 'selling'));

        $plan = $this->mcp('tools/call', ['name' => 'plan_setup', 'arguments' => []]);
        self::assertIsString(self::at($plan, 'result', 'structuredContent', 'fingerprint'));
        self::assertContains('selling.countries', array_column(self::arrayOf(self::at($plan, 'result', 'structuredContent', 'changes')), 'key'));
        delete_option('orillacart_setup_answers');
    }

    public function testAReadOnlyKeyIsOfferedOnlyWhatReads(): void
    {
        $this->connect('read');

        $names = self::names(self::at($this->mcp('tools/list'), 'result', 'tools'));
        self::assertContains('list_orders', $names);
        self::assertNotContains('create_tax_group', $names);
        self::assertSame(-32602, self::at($this->mcp('tools/call', ['name' => 'create_tax_group', 'arguments' => ['name' => 'Books']]), 'error', 'code'));
    }

    public function testWithoutAKeyTheEndpointAsksForOne(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], []);

        self::assertSame(401, $response->get_status());
        self::assertSame('Bearer realm="OrillaCart"', $response->get_headers()['WWW-Authenticate'] ?? null);
    }

    public function testAnEmptyResultStaysAnObject(): void
    {
        $this->connect('read');
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping'], ['MCP-Protocol-Version' => '2025-06-18']);

        self::assertSame('{"jsonrpc":"2.0","id":9,"result":{}}', wp_json_encode($response->get_data()));
    }

    private function connect(string $scope): void
    {
        $this->actAs('administrator');
        $made = $this->data($this->request('POST', '/api-keys', ['name' => 'Assistant', 'scope' => $scope], nonce: true));
        $key = self::textAt($made, 'key');
        wp_set_current_user(0);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
        $_SERVER['REQUEST_URI'] = '/wp-json/orillacart/v1/mcp';
        $user = apply_filters('determine_current_user', 0);
        wp_set_current_user(\is_int($user) ? $user : 0);
        self::assertGreaterThan(0, get_current_user_id());
    }

    /**
     * A modern (2026-07-28) request with its headers, and its answer as arrays.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function mcp(string $method, array $params = []): array
    {
        $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new \stdClass()];
        $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
        if (isset($params['name']) && \is_string($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $headers);
        $decoded = json_decode((string) wp_json_encode($response->get_data()), true);
        self::assertIsArray($decoded);

        return array_filter($decoded, \is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     */
    private function post(array $message, array $headers): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', self::NAMESPACE . '/mcp');
        $request->set_header('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }
        $request->set_body((string) wp_json_encode($message));
        $response = rest_do_request($request);
        // As WordPress serves it: the shop's REST answers pass rest_post_dispatch.
        $served = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);
        \assert($served instanceof \WP_REST_Response);

        return $served;
    }

    /**
     * The value at a path in decoded JSON; the test fails when the path is not there.
     */
    private static function at(mixed $data, int|string ...$path): mixed
    {
        foreach ($path as $step) {
            self::assertIsArray($data);
            self::assertArrayHasKey($step, $data);
            $data = $data[$step];
        }

        return $data;
    }

    /**
     * The names of the tools listed.
     *
     * @return list<mixed>
     */
    private static function names(mixed $tools): array
    {
        self::assertIsArray($tools);

        return array_column($tools, 'name');
    }

    /** The text at a path in decoded JSON. */
    private static function textAt(mixed $data, int|string ...$path): string
    {
        $value = self::at($data, ...$path);
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private static function arrayOf(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
