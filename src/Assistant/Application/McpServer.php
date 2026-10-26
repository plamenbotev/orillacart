<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\McpReply;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Application\Dto\ToolOutcome;

/**
 * The shop's MCP server: one JSON-RPC message in, one answer out, over Streamable HTTP with JSON answers.
 *
 * It speaks revision 2026-07-28, where every request carries its protocol version and the client's capabilities
 * in `_meta` and the HTTP headers repeat the method and the tool's name, and the earlier revisions whose
 * clients start with `initialize`. No session is kept: each request stands alone, as both allow.
 */
final readonly class McpServer
{
    public const string MODERN = '2026-07-28';

    /** Revisions with the initialize handshake, newest first. */
    public const array LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private const string META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    private const string META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    private const string META_SERVER = 'io.modelcontextprotocol/serverInfo';

    private const int PARSE_ERROR = -32700;
    private const int INVALID_REQUEST = -32600;
    private const int METHOD_NOT_FOUND = -32601;
    private const int INVALID_PARAMS = -32602;
    private const int HEADER_MISMATCH = -32020;
    private const int UNSUPPORTED_VERSION = -32022;

    /** Methods whose `Mcp-Name` header repeats a parameter. */
    private const array NAMED = ['tools/call' => 'name', 'prompts/get' => 'name', 'resources/read' => 'uri'];

    /** How long a client may keep the list of tools, in milliseconds: they change when add-ons do. */
    private const int LIST_TTL = 300_000;

    /** How long the owner has to answer whether a risky tool may run, in seconds. */
    private const int ASK_TTL = 900;

    /** The owner's yes, as the client's form asks for it. */
    private const string CONFIRM = 'confirm';

    /** The risks in words, for the question the owner is asked. */
    private const array RISK_WORDS = [
        'money' => 'Money moves.',
        'deleting' => 'Something is deleted for good.',
        'customers' => 'Customers hear of it.',
        'opening' => 'The shop opens or closes, or many settings change at once.',
    ];

    public function __construct(
        private AssistantTools $tools,
        private AssistantContext $context,
        private RequestStates $states,
    ) {}

    /**
     * @param mixed                 $message the decoded body; null when it was not JSON
     * @param array<string, string> $headers the request's headers, names in lower case
     */
    public function handle(mixed $message, array $headers, Caller $caller): McpReply
    {
        if ($message === null) {
            return self::error(400, null, self::PARSE_ERROR, 'The body is not JSON.');
        }
        if (!\is_array($message) || array_is_list($message)) {
            return self::error(400, null, self::INVALID_REQUEST, 'Send one JSON-RPC request per POST; batches are not taken.');
        }
        $id = \is_string($message['id'] ?? null) || \is_int($message['id'] ?? null) ? $message['id'] : null;
        $method = $message['method'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || !\is_string($method)) {
            return self::error(400, $id, self::INVALID_REQUEST, 'Not a JSON-RPC 2.0 request.');
        }
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];
        if (!\array_key_exists('id', $message)) {
            // A notification: initialized, cancelled. Nothing is kept, so each is taken and needs no answer.
            return new McpReply(202, null);
        }
        if ($id === null) {
            return self::error(400, null, self::INVALID_REQUEST, 'A request\'s id is a string or a number, never null.');
        }
        if ($method === 'initialize') {
            return $this->initialize($id, $params);
        }

        $version = $this->version($method, $params, $headers);
        if ($version instanceof McpReply) {
            return $version->body === null ? $version : new McpReply($version->status, ['id' => $id] + $version->body);
        }
        $modern = $version === self::MODERN;
        $meta = \is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        $result = match ($method) {
            'server/discover' => $this->discover(),
            'ping' => [],
            'tools/list' => $this->listTools($caller, $modern),
            'tools/call' => $this->callTool($params, $caller, $modern, $meta),
            'prompts/list' => self::list('prompts', [], $modern),
            'resources/list' => self::list('resources', [], $modern),
            'resources/templates/list' => self::list('resourceTemplates', [], $modern),
            default => null,
        };
        if ($result === null) {
            return self::error($modern ? 404 : 200, $id, self::METHOD_NOT_FOUND, "The shop has no method {$method}.");
        }
        if ($result instanceof McpReply) {
            return $result->body === null ? $result : new McpReply($result->status, ['id' => $id] + $result->body);
        }
        if ($modern) {
            $result += ['resultType' => 'complete'];
            $meta = \is_array($result['_meta'] ?? null) ? $result['_meta'] : [];
            $result['_meta'] = $meta + [self::META_SERVER => $this->serverInfo()];
        }

        return new McpReply(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result]);
    }

    /**
     * The versions the shop speaks, newest first.
     *
     * @return list<string>
     */
    public static function versions(): array
    {
        return [self::MODERN, ...self::LEGACY];
    }

    /**
     * The handshake of the earlier revisions: the client's version when the shop speaks it, else the newest of them.
     *
     * @param array<array-key, mixed> $params
     */
    private function initialize(int|string $id, array $params): McpReply
    {
        $asked = $params['protocolVersion'] ?? null;

        return new McpReply(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'protocolVersion' => \is_string($asked) && \in_array($asked, self::LEGACY, true) ? $asked : self::LEGACY[0],
            'capabilities' => self::capabilities(),
            'serverInfo' => $this->serverInfo(),
            'instructions' => $this->context->instructions(),
        ]]);
    }

    /**
     * The revision a request speaks, from its `_meta` and its headers, or the error that refuses it. A modern
     * request must repeat its version, method and name in the headers, and the headers must say what the body
     * says. A request without either is from a client older than the version header.
     *
     * @param array<array-key, mixed> $params
     * @param array<string, string>   $headers
     */
    private function version(string $method, array $params, array $headers): string|McpReply
    {
        $meta = \is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $declared = $meta[self::META_VERSION] ?? null;
        $header = $headers['mcp-protocol-version'] ?? null;
        if ($declared === null) {
            if ($header === null) {
                return '2025-03-26';
            }
            if (\in_array($header, self::LEGACY, true)) {
                return $header;
            }
            if ($header === self::MODERN) {
                return self::error(400, null, self::HEADER_MISMATCH, 'MCP-Protocol-Version says ' . self::MODERN . ' but the request has no io.modelcontextprotocol/protocolVersion in _meta.');
            }

            return self::unsupported($header);
        }
        if (!\is_string($declared)) {
            return self::error(400, null, self::INVALID_REQUEST, 'io.modelcontextprotocol/protocolVersion must be a string.');
        }
        if ($header !== null && $header !== $declared) {
            return self::error(400, null, self::HEADER_MISMATCH, "MCP-Protocol-Version says {$header} but _meta says {$declared}.");
        }
        if (!\in_array($declared, self::versions(), true)) {
            return self::unsupported($declared);
        }
        if ($declared !== self::MODERN) {
            return $declared;
        }
        if ($header === null) {
            return self::error(400, null, self::HEADER_MISMATCH, 'The MCP-Protocol-Version header is missing.');
        }
        if (($headers['mcp-method'] ?? null) !== $method) {
            return self::error(400, null, self::HEADER_MISMATCH, isset($headers['mcp-method']) ? "Mcp-Method says {$headers['mcp-method']} but the body says {$method}." : 'The Mcp-Method header is missing.');
        }
        if (isset(self::NAMED[$method])) {
            $named = $params[self::NAMED[$method]] ?? null;
            $given = isset($headers['mcp-name']) ? self::decoded($headers['mcp-name']) : null;
            if ($given === null) {
                return self::error(400, null, self::HEADER_MISMATCH, 'The Mcp-Name header is missing or not readable.');
            }
            if ($given !== $named) {
                return self::error(400, null, self::HEADER_MISMATCH, 'Mcp-Name does not match the body.');
            }
        }

        return $declared;
    }

    /** A header value, decoded when it uses the base64 sentinel: =?base64?…?=. */
    private static function decoded(string $value): ?string
    {
        if (preg_match('/^=\?base64\?([A-Za-z0-9+\/=]*)\?=$/', $value, $match) === 1) {
            $decoded = base64_decode($match[1], true);

            return $decoded === false ? null : $decoded;
        }

        return $value;
    }

    private static function unsupported(string $requested): McpReply
    {
        return new McpReply(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => [
            'code' => self::UNSUPPORTED_VERSION,
            'message' => 'Unsupported protocol version',
            'data' => ['supported' => self::versions(), 'requested' => $requested],
        ]]);
    }

    /** @return array<string, mixed> */
    private function discover(): array
    {
        return [
            'supportedVersions' => self::versions(),
            'capabilities' => self::capabilities(),
            'instructions' => $this->context->instructions(),
            'ttlMs' => self::LIST_TTL,
            'cacheScope' => 'private',
        ];
    }

    /** @return array<string, mixed> */
    private static function capabilities(): array
    {
        return ['tools' => ['listChanged' => false]];
    }

    /** @return array{name: string, title: string, version: string} */
    private function serverInfo(): array
    {
        return ['name' => 'orillacart', 'title' => $this->context->shopName(), 'version' => $this->context->version()];
    }

    /** @return array<string, mixed> */
    private function listTools(Caller $caller, bool $modern): array
    {
        return self::list('tools', array_map(static fn(ToolDefinition $tool): array => $tool->toMcp(), $this->tools->offered($caller)), $modern);
    }

    /**
     * @param list<mixed> $items
     *
     * @return array<string, mixed>
     */
    private static function list(string $key, array $items, bool $modern): array
    {
        $list = [$key => $items];

        // Each key sees its own tools, so shared caches may not keep the list.
        return $modern ? $list + ['ttlMs' => self::LIST_TTL, 'cacheScope' => 'private'] : $list;
    }

    /**
     * @param array<array-key, mixed> $params
     * @param array<array-key, mixed> $meta
     *
     * @return array<string, mixed>|McpReply
     */
    private function callTool(array $params, Caller $caller, bool $modern, array $meta): array|McpReply
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!\is_string($name)) {
            return self::error(200, null, self::INVALID_PARAMS, 'Name the tool to call.');
        }
        if (!\is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return self::error(200, null, self::INVALID_PARAMS, 'The arguments must be an object.');
        }
        $arguments = array_filter($arguments, \is_string(...), ARRAY_FILTER_USE_KEY);
        $tool = $this->tools->find($name, $caller);
        if ($tool === null) {
            return self::error(200, null, self::INVALID_PARAMS, "Unknown tool: {$name}");
        }
        if ($this->tools->asksFirst($tool, $caller)) {
            $asked = $this->ask($tool, $arguments, $params, $caller, $modern, $meta);
            if ($asked !== null) {
                return $asked;
            }
        }
        $outcome = $this->tools->call($name, $arguments, $caller);
        if ($outcome === null) {
            return self::error(200, null, self::INVALID_PARAMS, "Unknown tool: {$name}");
        }

        return self::toolResult($outcome, $modern);
    }

    /**
     * Asks the owner before a tool whose risk they want to be asked about: through the client (MCP elicitation,
     * a form with one yes), and runs it only on the retry that carries their yes and the state the question was
     * asked with, once. Null when the owner said yes; else the question, or why the tool did not run.
     *
     * @param array<string, mixed>    $arguments
     * @param array<array-key, mixed> $params
     * @param array<array-key, mixed> $meta
     *
     * @return array<string, mixed>|null
     */
    private function ask(ToolDefinition $tool, array $arguments, array $params, Caller $caller, bool $modern, array $meta): ?array
    {
        $capabilities = \is_array($meta[self::META_CAPABILITIES] ?? null) ? $meta[self::META_CAPABILITIES] : [];
        $elicitation = $capabilities['elicitation'] ?? null;
        if (!$modern || !\is_array($elicitation) || ($elicitation !== [] && !isset($elicitation['form']))) {
            return self::toolResult(ToolOutcome::refused(
                "{$tool->name} waits for the owner's yes (" . rtrim(self::RISK_WORDS[$tool->risk ?? ''] ?? '', '.') . '), and this client cannot ask them (MCP elicitation, revision ' . self::MODERN . '). Tell the owner what you would do; they can do it in the admin, or let this key do it without asking in Shop → Integrations.',
            ), $modern);
        }
        $digest = hash('sha256', $tool->name . "\n" . json_encode(self::sorted($arguments)));
        $responses = \is_array($params['inputResponses'] ?? null) ? $params['inputResponses'] : [];
        $state = $params['requestState'] ?? null;
        $answer = \is_array($responses[self::CONFIRM] ?? null) ? $responses[self::CONFIRM] : null;
        if ($answer !== null && \is_string($state)) {
            $asked = $this->states->redeem($state);
            if ($asked !== null && ($asked['user'] ?? null) === $caller->userId && ($asked['key'] ?? null) === $caller->keyId && ($asked['call'] ?? null) === $digest) {
                $content = \is_array($answer['content'] ?? null) ? $answer['content'] : [];
                if (($answer['action'] ?? null) === 'accept' && ($content[self::CONFIRM] ?? null) === true) {
                    return null;
                }

                return self::toolResult(ToolOutcome::refused('The owner said no: nothing was done.'), $modern);
            }
        }

        $given = $arguments === [] ? '' : "\n\n" . json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return [
            'resultType' => 'input_required',
            'inputRequests' => [self::CONFIRM => [
                'method' => 'elicitation/create',
                'params' => [
                    'mode' => 'form',
                    'message' => "The assistant wants to: {$tool->title}. " . (self::RISK_WORDS[$tool->risk ?? ''] ?? '') . "\n\n{$tool->description}{$given}",
                    'requestedSchema' => [
                        'type' => 'object',
                        'properties' => [self::CONFIRM => ['type' => 'boolean', 'title' => 'Yes, do it', 'default' => false]],
                        'required' => [self::CONFIRM],
                    ],
                ],
            ]],
            'requestState' => $this->states->issue(['user' => $caller->userId, 'key' => $caller->keyId, 'call' => $digest], self::ASK_TTL),
        ];
    }

    /**
     * Arguments with their keys in order at every depth, so the same call digests alike.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn(mixed $item): mixed => \is_array($item) ? self::sorted($item) : $item, $value);
    }

    /**
     * The answer as text, which every client reads, and as structured content: any JSON value from 2026-07-28 on,
     * an object only before.
     *
     * @return array<string, mixed>
     */
    private static function toolResult(ToolOutcome $outcome, bool $modern): array
    {
        if ($outcome->isError()) {
            $text = (string) $outcome->refusal;
            if ($outcome->data !== null) {
                $text .= "\n" . json_encode($outcome->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }

            return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
        }
        $text = json_encode($outcome->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
        $result = ['content' => [['type' => 'text', 'text' => $text === false ? '' : $text]], 'isError' => false];
        if ($outcome->data !== null && ($modern || \is_object($outcome->data) || (\is_array($outcome->data) && !array_is_list($outcome->data)))) {
            $result['structuredContent'] = $outcome->data;
        }

        return $result;
    }

    private static function error(int $status, int|string|null $id, int $code, string $message): McpReply
    {
        return new McpReply($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
