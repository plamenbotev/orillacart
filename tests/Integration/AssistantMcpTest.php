<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Assistant\Application\AssistantTools;
use OrillaCart\Assistant\Application\RouteTools;
use OrillaCart\Assistant\Infrastructure\AddOnToolSource;
use OrillaCart\Assistant\Infrastructure\WordPress\SignedRequestStates;
use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Extension\Assistant\AssistantTool;
use OrillaCart\Extension\Assistant\ToolCall;
use OrillaCart\Extension\Registry;
use OrillaCart\Integrations\Application\OAuthServer;
use OrillaCart\Platform\WordPress\Extensions\WordPressExtensions;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;

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
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        // The tax groups the tools made: later tests count the shop's groups.
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_tax_groups WHERE name IN ('Books', 'Logged in', 'By ability')");
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

        $tools = self::kernel()->container()->get(AssistantTools::class);
        self::assertInstanceOf(AssistantTools::class, $tools);
        self::assertSame([], $tools->duplicates(), 'no two sources give a tool the same name');

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

    public function testAnAddOnsToolsComeUnderItsName(): void
    {
        $this->connect('read_write');

        $tools = self::arrayOf(self::at($this->mcp('tools/list'), 'result', 'tools'));
        $names = self::names($tools);
        self::assertContains('e2e_echo', $names);

        $echo = $this->mcp('tools/call', ['name' => 'e2e_echo', 'arguments' => ['word' => 'hi']]);
        self::assertSame(['word' => 'hi', 'user' => get_current_user_id()], self::at($echo, 'result', 'structuredContent'));

        $refused = $this->mcp('tools/call', ['name' => 'e2e_stamp', 'arguments' => []]);
        self::assertTrue(self::at($refused, 'result', 'isError'));
        self::assertStringStartsWith('Give a word.', self::textAt($refused, 'result', 'content', 0, 'text'));

        $stamped = $this->mcp('tools/call', ['name' => 'e2e_stamp', 'arguments' => ['word' => 'mcp']]);
        self::assertFalse(self::at($stamped, 'result', 'isError'));
        self::assertSame('mcp', get_option('orillacart_e2e_stamp'));
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        self::assertSame(
            ['route' => 'CALL /assistant/e2e_stamp', 'via' => 'assistant', 'key_name' => 'Assistant'],
            $wpdb->get_row("SELECT route, via, key_name FROM {$wpdb->prefix}orillacart_change_log ORDER BY id DESC LIMIT 1", ARRAY_A),
        );
        delete_option('orillacart_e2e_stamp');
    }

    public function testAnAddOnToolWithAShopToolsNameIsLeftOutAndLogged(): void
    {
        global $wp_filter;
        self::assertIsArray($wp_filter);
        $hook = $wp_filter[WordPressExtensions::ACTION] ?? null;
        $before = $hook instanceof \WP_Hook ? clone $hook : null;
        $logger = new MemoryLogger();
        $registry = new WordPressExtensions([], $logger, static fn() => throw new \LogicException('not read'), static fn() => throw new \LogicException('not read'), static fn() => throw new \LogicException('not read'));
        $container = self::kernel()->container();
        $routeTools = $container->get(RouteTools::class);
        $log = $container->get(ChangeLog::class);
        self::assertInstanceOf(RouteTools::class, $routeTools);
        self::assertInstanceOf(ChangeLog::class, $log);
        try {
            remove_all_actions(WordPressExtensions::ACTION);
            add_action(WordPressExtensions::ACTION, static function (Registry $registry): void {
                $registry->assistantTool(new class implements AssistantTool {
                    public function name(): string
                    {
                        return 'list_orders';
                    }
                    public function addOn(): string
                    {
                        return 'Copycat';
                    }
                    public function title(): string
                    {
                        return 'Orders';
                    }
                    public function description(): string
                    {
                        return 'Not the shop\'s.';
                    }
                    public function inputSchema(): array
                    {
                        return ['type' => 'object'];
                    }
                    public function capability(): string
                    {
                        return 'manage_orillacart';
                    }
                    public function readOnly(): bool
                    {
                        return true;
                    }
                    public function risk(): ?string
                    {
                        return null;
                    }
                    public function run(array $arguments, ToolCall $call): array
                    {
                        return [];
                    }
                });
            });
            $source = new AddOnToolSource($registry, $routeTools, $log, $logger);
            self::assertSame([], $source->tools());
            self::assertSame([], $source->tools());
        } finally {
            if ($before === null) {
                unset($wp_filter[WordPressExtensions::ACTION]);
            } else {
                $wp_filter[WordPressExtensions::ACTION] = $before;
            }
        }

        self::assertCount(1, $logger->records, 'logged once, not on every list');
        self::assertStringContainsString('left out', $logger->records[0]['message']);
    }

    public function testWhatWordPressKeepsHasItsOwnTools(): void
    {
        $this->connect('read_write');
        // A 1x1 PNG.
        $png = base64_encode((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', true));

        $image = $this->mcp('tools/call', ['name' => 'upload_image', 'arguments' => ['file_name' => 'dot.png', 'file_base64' => $png, 'alt' => 'A dot']]);
        self::assertFalse(self::at($image, 'result', 'isError'));
        $imageId = self::at($image, 'result', 'structuredContent', 'id');
        self::assertIsInt($imageId);
        self::assertSame('A dot', get_post_meta($imageId, '_wp_attachment_image_alt', true));
        $text = $this->mcp('tools/call', ['name' => 'upload_image', 'arguments' => ['file_name' => 'note.txt', 'file_base64' => base64_encode('hello')]]);
        self::assertTrue(self::at($text, 'result', 'isError'), 'only pictures');

        $made = $this->mcp('tools/call', ['name' => 'create_product', 'arguments' => ['name' => 'Desk lamp', 'categories' => ['Assistant lighting > Assistant desk lamps'], 'image_ids' => [$imageId]]]);
        self::assertFalse(self::at($made, 'result', 'isError'));
        $productId = self::at($made, 'result', 'structuredContent', 'id');
        self::assertIsInt($productId);
        self::assertSame('draft', get_post_status($productId));
        self::assertSame($imageId, get_post_thumbnail_id($productId));
        self::assertSame(['Assistant desk lamps'], wp_get_post_terms($productId, 'orillacart_category', ['fields' => 'names']));

        $changed = $this->mcp('tools/call', ['name' => 'change_product_content', 'arguments' => ['id' => $productId, 'status' => 'publish', 'short_description' => 'Bright.']]);
        self::assertSame('publish', self::at($changed, 'result', 'structuredContent', 'status'));
        self::assertSame('Bright.', get_post_field('post_excerpt', $productId));
        self::assertSame('Desk lamp', get_the_title($productId), 'what was left out stays');

        $found = $this->mcp('tools/call', ['name' => 'find_products', 'arguments' => ['search' => 'Desk lamp']]);
        self::assertContains($productId, array_column(self::arrayOf(self::at($found, 'result', 'structuredContent', 'products')), 'id'));

        $category = $this->mcp('tools/call', ['name' => 'save_category', 'arguments' => ['name' => 'Floor lamps', 'order' => 3, 'layout' => 'list']]);
        $categoryId = self::at($category, 'result', 'structuredContent', 'id');
        self::assertIsInt($categoryId);
        self::assertSame(3, self::at($category, 'result', 'structuredContent', 'order'));
        $renamed = $this->mcp('tools/call', ['name' => 'save_category', 'arguments' => ['id' => $categoryId, 'name' => 'Standing lamps']]);
        self::assertSame(['Standing lamps', 'list'], [self::at($renamed, 'result', 'structuredContent', 'name'), self::at($renamed, 'result', 'structuredContent', 'layout')]);

        $page = wp_insert_post(['post_type' => 'page', 'post_title' => 'Terms', 'post_status' => 'draft', 'post_content' => 'Old.'], true);
        self::assertIsInt($page);
        $edited = $this->mcp('tools/call', ['name' => 'change_page', 'arguments' => ['id' => $page, 'content' => 'New terms.']]);
        self::assertSame('New terms.', self::at($edited, 'result', 'structuredContent', 'content'));
        self::assertTrue(self::at($this->mcp('tools/call', ['name' => 'change_page', 'arguments' => ['id' => $productId, 'content' => 'x']]), 'result', 'isError'), 'pages only');

        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        self::assertSame('CALL /assistant/change_page', $wpdb->get_var("SELECT route FROM {$wpdb->prefix}orillacart_change_log ORDER BY id DESC LIMIT 1"));

        wp_delete_post($page, true);
        wp_delete_post($productId, true);
        wp_delete_attachment($imageId, true);
        wp_delete_term($categoryId, 'orillacart_category');
        foreach (['Assistant desk lamps', 'Assistant lighting'] as $name) {
            $term = get_term_by('name', $name, 'orillacart_category');
            if ($term instanceof \WP_Term) {
                wp_delete_term($term->term_id, 'orillacart_category');
            }
        }
    }

    public function testAReadOnlyKeyIsOfferedOnlyWhatReads(): void
    {
        $this->connect('read');

        $names = self::names(self::at($this->mcp('tools/list'), 'result', 'tools'));
        self::assertContains('list_orders', $names);
        self::assertNotContains('create_tax_group', $names);
        self::assertSame(-32602, self::at($this->mcp('tools/call', ['name' => 'create_tax_group', 'arguments' => ['name' => 'Books']]), 'error', 'code'));
    }

    public function testTheOwnersYesIsSignedAndGoodOnce(): void
    {
        $states = new SignedRequestStates();

        $state = $states->issue(['user' => 1, 'call' => 'abc'], 60);
        [$body, $signature] = explode('.', $state);
        self::assertNull($states->redeem($body . 'x.' . $signature), 'a changed state is refused');
        self::assertSame(['user' => 1, 'call' => 'abc'], $states->redeem($state));
        self::assertNull($states->redeem($state), 'a yes is good once');
        self::assertNull($states->redeem($states->issue(['user' => 1], -1)), 'a state that ran out is refused');
    }

    public function testAManagerLoggedInCanCallAToolThatChangesSomething(): void
    {
        $this->actAs('administrator');
        $request = new \WP_REST_Request('POST', self::NAMESPACE . '/mcp');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('MCP-Protocol-Version', '2025-06-18');
        $request->set_body((string) wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'create_tax_group', 'arguments' => ['name' => 'Logged in']]]));
        $answer = json_decode((string) wp_json_encode(rest_do_request($request)->get_data()), true);

        self::assertFalse(self::at($answer, 'result', 'isError'), 'the endpoint checked the nonce; the route the tool runs does not ask again');
        self::assertSame('Logged in', self::at($answer, 'result', 'structuredContent', 'name'));
    }

    public function testTheToolsAreWordPressAbilitiesExceptTheRiskyOnes(): void
    {
        if (!\function_exists('wp_get_ability')) {
            self::markTestSkipped('WordPress before 6.9 has no Abilities API.');
        }
        $this->actAs('administrator');

        $settings = wp_get_ability('orillacart/show-settings');
        self::assertInstanceOf(\WP_Ability::class, $settings);
        self::assertSame('orillacart', $settings->get_category());
        self::assertTrue(self::at($settings->get_meta_item('annotations'), 'readonly'));
        $read = $settings->execute();
        self::assertIsArray($read);
        self::assertArrayHasKey('currency', $read);

        $made = wp_get_ability('orillacart/create-tax-group')?->execute(['name' => 'By ability']);
        self::assertIsArray($made);
        self::assertSame('By ability', $made['name']);
        $refused = wp_get_ability('orillacart/create-tax-group')?->execute(['name' => '']);
        self::assertInstanceOf(\WP_Error::class, $refused);

        self::assertFalse(wp_has_ability('orillacart/refund-order'), 'abilities cannot ask the owner first');
        self::assertTrue(wp_has_ability('orillacart/e2e-echo'), 'add-ons\' tools too');
    }

    public function testWithoutAKeyTheEndpointAsksForOne(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], []);

        self::assertSame(401, $response->get_status());
        $challenge = $response->get_headers()['WWW-Authenticate'] ?? '';
        self::assertIsString($challenge);
        self::assertStringStartsWith('Bearer realm="OrillaCart"', $challenge);
        $oauth = self::kernel()->container()->get(OAuthServer::class);
        self::assertInstanceOf(OAuthServer::class, $oauth);
        // With OAuth on offer, the challenge says where its server is (RFC 9728).
        self::assertSame($oauth->available(), str_contains($challenge, 'resource_metadata="' . rest_url('orillacart/v1/oauth/protected-resource') . '"'));
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
