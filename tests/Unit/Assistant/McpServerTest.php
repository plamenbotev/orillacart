<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant;

use OrillaCart\Assistant\Application\AssistantContext;
use OrillaCart\Assistant\Application\AssistantTools;
use OrillaCart\Assistant\Application\Dto\AssistantChoices;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\McpReply;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Application\Dto\ToolOutcome;
use OrillaCart\Assistant\Application\McpServer;
use OrillaCart\Assistant\Application\RequestStates;
use OrillaCart\Assistant\Application\ToolSource;
use OrillaCart\Assistant\Application\ToolSources;
use OrillaCart\Shared\Http\Security\Authorization;
use PHPUnit\Framework\TestCase;

/**
 * The MCP server answers both eras: 2026-07-28's requests that carry their version in _meta and repeat it in the
 * headers, and the earlier revisions' initialize handshake.
 */
final class McpServerTest extends TestCase
{
    private const array META = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []];

    /** @var \ArrayObject<int, array{string, array<string, mixed>}> the tools the fake source ran */
    private \ArrayObject $calls;

    private RequestStates $states;

    protected function setUp(): void
    {
        $this->calls = new \ArrayObject();
        $this->states = new class implements RequestStates {
            /** @var array<string, array<string, mixed>> */
            private array $issued = [];

            public function issue(array $payload, int $seconds): string
            {
                $state = 'state-' . \count($this->issued);
                $this->issued[$state] = $payload;

                return $state;
            }

            public function redeem(string $state): ?array
            {
                $payload = $this->issued[$state] ?? null;
                unset($this->issued[$state]);

                return $payload;
            }
        };
    }

    public function testARiskyToolAsksTheOwnerAndRunsOnTheirYesOnce(): void
    {
        $call = ['name' => 'refund_thing', 'arguments' => ['id' => 4, 'amount' => '5.00']];
        $asked = $this->asking($call);
        self::assertSame('input_required', self::at($asked->body, 'result', 'resultType'));
        self::assertSame('elicitation/create', self::at($asked->body, 'result', 'inputRequests', 'confirm', 'method'));
        self::assertStringContainsString('Money moves.', self::textAt($asked->body, 'result', 'inputRequests', 'confirm', 'params', 'message'));
        self::assertSame([], $this->calls->getArrayCopy(), 'nothing ran before the owner answered');
        $state = self::textAt($asked->body, 'result', 'requestState');

        $yes = ['confirm' => ['action' => 'accept', 'content' => ['confirm' => true]]];
        $done = $this->asking($call + ['inputResponses' => $yes, 'requestState' => $state]);
        self::assertSame('complete', self::at($done->body, 'result', 'resultType'));
        self::assertFalse(self::at($done->body, 'result', 'isError'));
        self::assertSame([['refund_thing', ['id' => 4, 'amount' => '5.00']]], $this->calls->getArrayCopy());

        $again = $this->asking($call + ['inputResponses' => $yes, 'requestState' => $state]);
        self::assertSame('input_required', self::at($again->body, 'result', 'resultType'), 'a yes is good once');
        self::assertCount(1, $this->calls);
    }

    public function testAYesIsForTheCallItWasAskedFor(): void
    {
        $asked = $this->asking(['name' => 'refund_thing', 'arguments' => ['amount' => '5.00']]);
        $yes = ['confirm' => ['action' => 'accept', 'content' => ['confirm' => true]]];

        $other = $this->asking(['name' => 'refund_thing', 'arguments' => ['amount' => '500.00'], 'inputResponses' => $yes, 'requestState' => self::textAt($asked->body, 'result', 'requestState')]);
        self::assertSame('input_required', self::at($other->body, 'result', 'resultType'));
        self::assertSame([], $this->calls->getArrayCopy());
    }

    public function testANoRunsNothing(): void
    {
        $asked = $this->asking(['name' => 'refund_thing', 'arguments' => []]);
        $no = $this->asking(['name' => 'refund_thing', 'arguments' => [], 'inputResponses' => ['confirm' => ['action' => 'decline']], 'requestState' => self::textAt($asked->body, 'result', 'requestState')]);

        self::assertTrue(self::at($no->body, 'result', 'isError'));
        self::assertStringContainsString('said no', self::textAt($no->body, 'result', 'content', 0, 'text'));
        self::assertSame([], $this->calls->getArrayCopy());
    }

    public function testAClientThatCannotAskIsRefusedUnlessTheOwnerLetsTheKey(): void
    {
        $refused = $this->modern('tools/call', ['name' => 'refund_thing', 'arguments' => []], ['mcp-name' => 'refund_thing']);
        self::assertTrue(self::at($refused->body, 'result', 'isError'));
        self::assertStringContainsString('cannot ask', self::textAt($refused->body, 'result', 'content', 0, 'text'));
        self::assertSame([], $this->calls->getArrayCopy());

        $trusted = new Caller(1, 7, false, new AssistantChoices(ask: ['deleting']));
        $run = $this->modern('tools/call', ['name' => 'refund_thing', 'arguments' => []], ['mcp-name' => 'refund_thing'], $trusted);
        self::assertFalse(self::at($run->body, 'result', 'isError'));
        self::assertCount(1, $this->calls);
    }

    public function testAKeyThatFindsOnDemandIsOfferedThreeToolsThatReachTheRest(): void
    {
        $finder = new Caller(1, 7, false, new AssistantChoices(list: AssistantChoices::FIND_ON_DEMAND));

        self::assertSame(['describe_tool', 'find_tools', 'run_tool'], self::names(self::at($this->modern('tools/list', caller: $finder)->body, 'result', 'tools')));

        $found = $this->modern('tools/call', ['name' => 'find_tools', 'arguments' => ['words' => 'money']], ['mcp-name' => 'find_tools'], $finder);
        self::assertSame('refund_thing', self::at($found->body, 'result', 'structuredContent', 'tools', 0, 'name'));

        $described = $this->modern('tools/call', ['name' => 'describe_tool', 'arguments' => ['name' => 'show_thing']], ['mcp-name' => 'describe_tool'], $finder);
        self::assertSame(['type' => 'object'], self::at($described->body, 'result', 'structuredContent', 'inputSchema'));

        $run = $this->modern('tools/call', ['name' => 'run_tool', 'arguments' => ['name' => 'show_thing', 'arguments' => ['id' => 2]]], ['mcp-name' => 'run_tool'], $finder);
        self::assertSame(['id' => 2, 'name' => 'Mug'], self::at($run->body, 'result', 'structuredContent'));

        $risky = $this->modern('tools/call', ['name' => 'run_tool', 'arguments' => ['name' => 'refund_thing', 'arguments' => []]], ['mcp-name' => 'run_tool'], $finder);
        self::assertTrue(self::at($risky->body, 'result', 'isError'), 'run_tool asks first as the tool itself would');
        self::assertCount(1, $this->calls);
    }

    public function testPromptsAndTheShopsOverview(): void
    {
        $prompts = self::at($this->modern('prompts/list')->body, 'result', 'prompts');
        self::assertSame(['set_up_shop', 'review_orders', 'running_low'], self::names($prompts));

        $setup = $this->modern('prompts/get', ['name' => 'set_up_shop'], ['mcp-name' => 'set_up_shop']);
        self::assertStringContainsString('apply_setup', self::textAt($setup->body, 'result', 'messages', 0, 'content', 'text'));
        self::assertSame(-32602, self::at($this->modern('prompts/get', ['name' => 'nope'], ['mcp-name' => 'nope'])->body, 'error', 'code'));

        self::assertSame('orillacart://shop', self::at($this->modern('resources/list')->body, 'result', 'resources', 0, 'uri'));
        $read = $this->modern('resources/read', ['uri' => 'orillacart://shop'], ['mcp-name' => 'orillacart://shop']);
        self::assertSame(['name' => 'Mugs & Co', 'currency' => 'EUR'], json_decode(self::textAt($read->body, 'result', 'contents', 0, 'text'), true));
    }

    public function testTheKeysAreasLimitWhatItIsOffered(): void
    {
        $caller = new Caller(1, 7, false, new AssistantChoices(offAreas: ['Things']));

        self::assertSame([], self::at($this->modern('tools/list', caller: $caller)->body, 'result', 'tools'));
        $left = new Caller(1, 7, false, new AssistantChoices(off: ['show_thing']));
        self::assertNotContains('show_thing', self::names(self::at($this->modern('tools/list', caller: $left)->body, 'result', 'tools')));
    }

    /**
     * A tools/call from a client that can ask its user (elicitation).
     *
     * @param array{name: string, arguments: array<string, mixed>, inputResponses?: array<string, mixed>, requestState?: string} $params
     */
    private function asking(array $params): McpReply
    {
        $meta = ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => ['elicitation' => ['form' => []]]]];

        return $this->send(
            ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => $meta + $params],
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call', 'mcp-name' => $params['name']],
        );
    }

    public function testInitializeAgreesOnAnEarlierRevision(): void
    {
        $reply = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);

        self::assertSame(200, $reply->status);
        self::assertSame('2025-06-18', self::at($reply->body, 'result', 'protocolVersion'));
        self::assertSame(['listChanged' => false], self::at($reply->body, 'result', 'capabilities', 'tools'));
        self::assertArrayHasKey('prompts', self::arrayOf(self::at($reply->body, 'result', 'capabilities')));
        self::assertSame(['name' => 'orillacart', 'title' => 'Mugs & Co', 'version' => '9.9'], self::at($reply->body, 'result', 'serverInfo'));
        self::assertSame('Be careful.', self::at($reply->body, 'result', 'instructions'));

        $unknown = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-01-01']]);
        self::assertSame('2025-11-25', self::at($unknown->body, 'result', 'protocolVersion'));
    }

    public function testANotificationIsTakenWithoutAnAnswer(): void
    {
        $reply = $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        self::assertSame(202, $reply->status);
        self::assertNull($reply->body);
    }

    public function testDiscoverNamesTheVersionsAndTheServer(): void
    {
        $reply = $this->modern('server/discover');

        self::assertSame(200, $reply->status);
        $result = self::arrayAt($reply->body, 'result');
        self::assertSame('complete', self::at($result, 'resultType'));
        self::assertSame(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], self::at($result, 'supportedVersions'));
        self::assertSame('orillacart', self::at($result, '_meta', 'io.modelcontextprotocol/serverInfo', 'name'));
        self::assertSame('private', self::at($result, 'cacheScope'));
    }

    public function testToolsAreListedInOrderAndAReadOnlyKeySeesOnlyReads(): void
    {
        $reply = $this->modern('tools/list');
        self::assertSame(['change_thing', 'refund_thing', 'show_thing'], self::names(self::at($reply->body, 'result', 'tools')));
        self::assertSame(['title' => 'Show thing', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false], self::at($reply->body, 'result', 'tools', 2, 'annotations'));
        self::assertIsInt(self::at($reply->body, 'result', 'ttlMs'));

        $readOnly = $this->modern('tools/list', caller: new Caller(1, 7, true));
        self::assertSame(['show_thing'], self::names(self::at($readOnly->body, 'result', 'tools')));

        $legacy = $this->send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], ['mcp-protocol-version' => '2025-06-18']);
        self::assertArrayNotHasKey('resultType', self::arrayAt($legacy->body, 'result'));
        self::assertArrayNotHasKey('ttlMs', self::arrayAt($legacy->body, 'result'));
    }

    public function testACallRunsTheToolAndARefusalIsTheToolsError(): void
    {
        $reply = $this->modern('tools/call', ['name' => 'show_thing', 'arguments' => ['id' => 4]], ['mcp-name' => 'show_thing']);
        self::assertSame([['show_thing', ['id' => 4]]], $this->calls->getArrayCopy());
        self::assertFalse(self::at($reply->body, 'result', 'isError'));
        self::assertSame(['id' => 4, 'name' => 'Mug'], self::at($reply->body, 'result', 'structuredContent'));
        self::assertSame("{\n    \"id\": 4,\n    \"name\": \"Mug\"\n}", self::at($reply->body, 'result', 'content', 0, 'text'));

        $refused = $this->modern('tools/call', ['name' => 'change_thing', 'arguments' => []], ['mcp-name' => 'change_thing']);
        self::assertTrue(self::at($refused->body, 'result', 'isError'));
        self::assertStringStartsWith('Some fields are not valid.', self::textAt($refused->body, 'result', 'content', 0, 'text'));
        self::assertStringContainsString('"field": "name"', self::textAt($refused->body, 'result', 'content', 0, 'text'));
    }

    public function testAnUnknownToolIsAProtocolError(): void
    {
        $reply = $this->modern('tools/call', ['name' => 'nope'], ['mcp-name' => 'nope']);

        self::assertSame(-32602, self::at($reply->body, 'error', 'code'));
        self::assertSame(5, self::at($reply->body, 'id'));
        // A read-only key is not offered a tool that changes, so it cannot call it either.
        $hidden = $this->modern('tools/call', ['name' => 'change_thing', 'arguments' => ['name' => 'x']], ['mcp-name' => 'change_thing'], new Caller(1, 7, true));
        self::assertSame(-32602, self::at($hidden->body, 'error', 'code'));
        self::assertSame([], $this->calls->getArrayCopy());
    }

    public function testHeadersMustSayWhatTheBodySays(): void
    {
        $method = $this->modern('tools/list', headers: ['mcp-method' => 'tools/call']);
        self::assertSame(400, $method->status);
        self::assertSame(-32020, self::at($method->body, 'error', 'code'));
        self::assertSame(5, self::at($method->body, 'id'));

        $name = $this->modern('tools/call', ['name' => 'show_thing'], ['mcp-name' => 'change_thing']);
        self::assertSame(-32020, self::at($name->body, 'error', 'code'));

        $missing = $this->modern('tools/call', ['name' => 'show_thing']);
        self::assertSame(-32020, self::at($missing->body, 'error', 'code'));

        $encoded = $this->modern('tools/call', ['name' => 'show_thing', 'arguments' => ['id' => 1]], ['mcp-name' => '=?base64?' . base64_encode('show_thing') . '?=']);
        self::assertFalse(self::at($encoded->body, 'result', 'isError'));

        $version = $this->modern('tools/list', headers: ['mcp-protocol-version' => '2025-11-25']);
        self::assertSame(-32020, self::at($version->body, 'error', 'code'));
    }

    public function testAnUnsupportedVersionListsTheSupportedOnes(): void
    {
        $reply = $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '1900-01-01']]], ['mcp-protocol-version' => '1900-01-01']);

        self::assertSame(400, $reply->status);
        self::assertSame(-32022, self::at($reply->body, 'error', 'code'));
        self::assertSame('1900-01-01', self::at($reply->body, 'error', 'data', 'requested'));
        self::assertContains('2026-07-28', self::arrayAt($reply->body, 'error', 'data', 'supported'));
    }

    public function testAnUnknownMethodIsNotFound(): void
    {
        self::assertSame(404, $this->modern('nope/nope')->status);
        $legacy = $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'nope/nope'], ['mcp-protocol-version' => '2025-06-18']);
        self::assertSame(200, $legacy->status);
        self::assertSame(-32601, self::at($legacy->body, 'error', 'code'));
    }

    public function testMalformedMessagesAreRefused(): void
    {
        self::assertSame(-32700, self::at($this->send(null)->body, 'error', 'code'));
        self::assertSame(-32600, self::at($this->send([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']])->body, 'error', 'code'));
        self::assertSame(-32600, self::at($this->send(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'])->body, 'error', 'code'));
        self::assertSame(-32600, self::at($this->send(['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping'])->body, 'error', 'code'));
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     */
    private function modern(string $method, array $params = [], array $headers = [], ?Caller $caller = null): McpReply
    {
        return $this->send(
            ['jsonrpc' => '2.0', 'id' => 5, 'method' => $method, 'params' => $params + ['_meta' => self::META]],
            $headers + ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => $method],
            $caller,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(mixed $message, array $headers = [], ?Caller $caller = null): McpReply
    {
        $source = new class ($this->calls) implements ToolSource {
            /** @param \ArrayObject<int, array{string, array<string, mixed>}> $calls */
            public function __construct(private \ArrayObject $calls) {}

            public function tools(): array
            {
                return [
                    new ToolDefinition('show_thing', 'Show thing', 'Shows a thing.', ['type' => 'object'], true, false, true, null, 'Things', 'manage_orillacart'),
                    new ToolDefinition('change_thing', 'Change thing', 'Changes a thing.', ['type' => 'object'], false, false, true, null, 'Things', 'manage_orillacart'),
                    new ToolDefinition('refund_thing', 'Refund thing', 'Gives the money back.', ['type' => 'object'], false, false, false, 'money', 'Things', 'manage_orillacart'),
                    new ToolDefinition('secret_thing', 'Secret thing', 'Not for managers.', ['type' => 'object'], true, false, true, null, 'Things', 'manage_options'),
                ];
            }

            public function run(ToolDefinition $tool, array $arguments, Caller $caller): ToolOutcome
            {
                $this->calls->append([$tool->name, $arguments]);
                if ($tool->name === 'change_thing' && !isset($arguments['name'])) {
                    return ToolOutcome::refused('Some fields are not valid.', ['violations' => [['field' => 'name', 'message' => 'This value should not be blank.']]]);
                }

                return ToolOutcome::done(['id' => $arguments['id'] ?? 0, 'name' => 'Mug']);
            }
        };
        $sources = new readonly class ($source) implements ToolSources {
            public function __construct(private ToolSource $source) {}

            public function all(): array
            {
                return [$this->source];
            }
        };
        $authorization = new class implements Authorization {
            public function isLoggedIn(): bool
            {
                return true;
            }

            public function userId(): int
            {
                return 1;
            }

            public function isGranted(string $capability): bool
            {
                return $capability === 'manage_orillacart';
            }
        };
        $context = new class implements AssistantContext {
            public function shopName(): string
            {
                return 'Mugs & Co';
            }

            public function version(): string
            {
                return '9.9';
            }

            public function instructions(): string
            {
                return 'Be careful.';
            }

            public function overview(): array
            {
                return ['name' => 'Mugs & Co', 'currency' => 'EUR'];
            }
        };

        return new McpServer(new AssistantTools($sources, $authorization), $context, $this->states)->handle($message, $headers, $caller ?? new Caller(1, 7, false));
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
     * The array at a path in decoded JSON.
     *
     * @return array<mixed>
     */
    private static function arrayAt(mixed $data, int|string ...$path): array
    {
        $value = self::at($data, ...$path);
        self::assertIsArray($value);

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
