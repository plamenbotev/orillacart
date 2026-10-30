<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

use OrillaCart\Assistant\Application\Dto\AssistantChoices;
use OrillaCart\Assistant\Application\Dto\CallApproval;
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

    /** The most of what a call would do a client's question carries; the shop's approval page shows all of it. */
    private const int ASK_MOST = 4000;

    /** The owner's yes, as the client's form asks for it. */
    private const string CONFIRM = 'confirm';

    /** The shop at a glance, as a resource. */
    private const array OVERVIEW = [
        'uri' => 'orillacart://shop',
        'name' => 'shop',
        'title' => 'The shop at a glance',
        'description' => 'The shop\'s name and address, country and currency, whether visitors can order, and what still stops it from selling.',
        'mimeType' => 'application/json',
    ];

    /** Ways to start, for the owner to pick in their client. */
    private const array PROMPTS = [
        'set_up_shop' => [
            'name' => 'set_up_shop',
            'title' => 'Set up my shop',
            'description' => 'Walks the owner through the setup: their business, where they sell, tax, shipping, payments, legal pages and emails; shows the plan and applies it when they agree.',
            'text' => <<<'TEXT'
                Help me set up my shop so that it is ready to sell and follows the rules where I sell.

                1. Read the shop resource (or show_readiness) and show_setup_answers to see what is done.
                2. Ask me about my business, one step at a time, in plain words: who and where I sell to, whether I am registered for VAT or sales tax, how I ship, how I take payment, and who sends the emails. show_country_profile tells you what my country asks.
                3. Save each step's answers with its tool (setup_answer_business, setup_answer_selling, setup_answer_tax, and so on), correcting what a refusal names.
                4. Call plan_setup and tell me, in plain words, what would be created, changed, kept or replaced. Point out anything marked as a warning.
                5. Only when I say yes, call apply_setup with the plan's fingerprint. Then show_readiness, and tell me what still stops the shop or is advised.

                Do not invent legal facts: the legal pages are drafts for me or a lawyer to check.
                TEXT,
        ],
        'review_orders' => [
            'name' => 'review_orders',
            'title' => 'Review today\'s orders',
            'description' => 'Goes through the day\'s orders and the ones that need attention, and says what to do about each; changes nothing unasked.',
            'text' => <<<'TEXT'
                Review my orders for today.

                Use list_orders with placed_from set to the start of today in the shop's time zone, and again with attention: true for the orders that need me. For each order that needs something (unpaid, on hold, backordered, a failed payment, not shipped when it should be), say what it needs and what you would do. Then give me the day's count and total.

                Do not change anything until I say which actions to take.
                TEXT,
        ],
        'running_low' => [
            'name' => 'running_low',
            'title' => 'What is running low',
            'description' => 'Finds the products whose stock is at or below their stockroom\'s low-stock warning, and suggests what to reorder.',
            'text' => <<<'TEXT'
                Tell me what is running low.

                Use list_stockrooms for each stockroom's low-stock warning, then page through show_stock_sheet for each stockroom. List the products and variations at or below the warning, and those owed on backorder, with how many are left. Suggest how many to reorder, from what sold lately (show_products_report) if you can.

                Do not change any stock levels.
                TEXT,
        ],
    ];

    /** The three tools a key that finds its tools on demand is offered. */
    public const string FIND = 'find_tools';
    public const string DESCRIBE = 'describe_tool';
    public const string RUN = 'run_tool';

    /** The finder's names, which no other tool may take. */
    public const array FINDER = [self::FIND, self::DESCRIBE, self::RUN];

    /** The risks in words, for the question the owner is asked. */
    private const array RISK_WORDS = [
        'money' => 'Money moves.',
        'deleting' => 'Something is deleted.',
        'customers' => 'Customers hear of it.',
        'opening' => 'The shop opens or closes, or many settings change at once.',
        'publishing' => 'What customers read or agree to changes.',
    ];

    public function __construct(
        private AssistantTools $tools,
        private AssistantContext $context,
        private RequestStates $states,
        private CallApprovals $approvals,
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
            'prompts/list' => self::list('prompts', array_map(static fn(array $prompt): array => array_diff_key($prompt, ['text' => true]), array_values(self::PROMPTS)), $modern),
            'prompts/get' => $this->prompt($params),
            'resources/list' => self::list('resources', [self::OVERVIEW], $modern),
            'resources/read' => $this->resource($params),
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
        return ['tools' => ['listChanged' => false], 'prompts' => ['listChanged' => false], 'resources' => ['listChanged' => false, 'subscribe' => false]];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, mixed>|McpReply
     */
    private function prompt(array $params): array|McpReply
    {
        $prompt = self::PROMPTS[\is_string($params['name'] ?? null) ? $params['name'] : ''] ?? null;
        if ($prompt === null) {
            return self::error(200, null, self::INVALID_PARAMS, 'No such prompt; prompts/list names them.');
        }

        return ['description' => $prompt['description'], 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $prompt['text']]]]];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, mixed>|McpReply
     */
    private function resource(array $params): array|McpReply
    {
        if (($params['uri'] ?? null) !== self::OVERVIEW['uri']) {
            return self::error(200, null, self::INVALID_PARAMS, 'No such resource; resources/list names them.');
        }
        $text = json_encode($this->context->overview(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return ['contents' => [['uri' => self::OVERVIEW['uri'], 'mimeType' => 'application/json', 'text' => $text === false ? '{}' : $text]]];
    }

    /** @return array{name: string, title: string, version: string} */
    private function serverInfo(): array
    {
        return ['name' => 'orillacart', 'title' => $this->context->shopName(), 'version' => $this->context->version()];
    }

    /** @return array<string, mixed> */
    private function listTools(Caller $caller, bool $modern): array
    {
        $tools = $caller->choices->list === AssistantChoices::FIND_ON_DEMAND ? self::finders() : $this->tools->offered($caller);

        return self::list('tools', array_map(static fn(ToolDefinition $tool): array => $tool->toMcp(), $tools), $modern);
    }

    /**
     * The tools of a key that finds the others on demand, for clients that take few tools.
     *
     * @return list<ToolDefinition>
     */
    private static function finders(): array
    {
        $name = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128];

        return [
            new ToolDefinition(self::DESCRIBE, 'Describe tool', 'One of the shop\'s tools in full: what it does, what it risks, and its arguments as JSON Schema. Find it with find_tools; run it with run_tool.', ['type' => 'object', 'properties' => ['name' => $name], 'required' => ['name'], 'additionalProperties' => false], true, false, true, null, 'Assistant', 'manage_orillacart'),
            new ToolDefinition(self::FIND, 'Find tools', 'The shop\'s tools whose name, title or description has the words (orders, refund, stock, tax, setup…), or all of an area; nothing changes. Each comes with its name, what it does and whether it only reads.', ['type' => 'object', 'properties' => ['words' => ['type' => 'string', 'maxLength' => 200], 'area' => ['type' => 'string', 'maxLength' => 100]], 'additionalProperties' => false], true, false, true, null, 'Assistant', 'manage_orillacart'),
            new ToolDefinition(self::RUN, 'Run tool', 'Runs one of the shop\'s tools with its arguments, as describe_tool gives them. Some ask the owner first.', ['type' => 'object', 'properties' => ['name' => $name, 'arguments' => ['type' => 'object']], 'required' => ['name'], 'additionalProperties' => false], false, false, false, null, 'Assistant', 'manage_orillacart'),
        ];
    }

    /**
     * The finders' answers: tools found, one described.
     *
     * @param array<string, mixed> $arguments
     */
    private function find(string $name, array $arguments, Caller $caller): ToolOutcome
    {
        $offered = $this->tools->offered($caller);
        if ($name === self::DESCRIBE) {
            foreach ($offered as $tool) {
                if ($tool->name === ($arguments['name'] ?? null)) {
                    return ToolOutcome::done($tool->toMcp() + ['area' => $tool->area, 'risk' => $tool->risk]);
                }
            }

            return ToolOutcome::refused('No tool of that name; find_tools lists them.');
        }
        $words = \is_string($arguments['words'] ?? null) ? preg_split('/\W+/u', mb_strtolower($arguments['words']), -1, PREG_SPLIT_NO_EMPTY) : [];
        $area = \is_string($arguments['area'] ?? null) ? mb_strtolower($arguments['area']) : null;
        $scored = [];
        foreach ($offered as $tool) {
            if ($area !== null && mb_strtolower($tool->area) !== $area) {
                continue;
            }
            $text = mb_strtolower($tool->name . ' ' . $tool->title . ' ' . $tool->description);
            $score = 0;
            foreach (\is_array($words) ? $words : [] as $word) {
                $score += substr_count(mb_strtolower($tool->name), $word) * 3 + substr_count($text, $word);
            }
            if ($words === [] || $score > 0) {
                $scored[] = [$score, $tool];
            }
        }
        usort($scored, static fn(array $a, array $b): int => [$b[0], $a[1]->name] <=> [$a[0], $b[1]->name]);
        $found = array_map(static fn(array $pair): array => ['name' => $pair[1]->name, 'description' => $pair[1]->description, 'area' => $pair[1]->area, 'read_only' => $pair[1]->readOnly, 'risk' => $pair[1]->risk], \array_slice($scored, 0, 40));

        return ToolOutcome::done(['tools' => $found, 'areas' => array_values(array_unique(array_map(static fn(ToolDefinition $tool): string => $tool->area, $offered)))]);
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
        if ($caller->choices->list === AssistantChoices::FIND_ON_DEMAND) {
            if ($name === self::FIND || $name === self::DESCRIBE) {
                return self::toolResult($this->find($name, $arguments, $caller), $modern);
            }
            if ($name === self::RUN) {
                $inner = $arguments['name'] ?? null;
                if (!\is_string($inner) || \in_array($inner, [self::FIND, self::DESCRIBE, self::RUN], true)) {
                    return self::error(200, null, self::INVALID_PARAMS, 'run_tool runs one of the shop\'s tools: name it.');
                }

                return $this->callTool(['name' => $inner, 'arguments' => $arguments['arguments'] ?? []] + array_intersect_key($params, ['inputResponses' => true, 'requestState' => true]), $caller, $modern, $meta);
            }
        }
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
        $digest = hash('sha256', $tool->name . "\n" . json_encode(self::sorted($arguments)));
        $page = $this->approvals->find($caller, $digest);
        if (!$modern || !\is_array($elicitation) || ($elicitation !== [] && !isset($elicitation['form'])) || ($page !== null && $page->state !== CallApproval::WAITING)) {
            // Answered on the shop's page (where a long question is read whole), that answer holds.
            return $this->approvalPage($tool, $arguments, $caller, $digest, $modern);
        }
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

        $what = $this->what($tool, $arguments, $caller);
        $risk = self::RISK_WORDS[$tool->risk ?? ''] ?? '';
        if (mb_strlen($what) > self::ASK_MOST) {
            // Too long for a client's question: its start there, the whole of it on the shop's page.
            $page ??= $this->approvals->ask($caller, $digest, $tool->title, $risk, $what, self::ASK_TTL);
            $what = mb_substr($what, 0, self::ASK_MOST) . "…\n\nThis is the start of it. The whole of it (" . mb_strlen($what) . ' characters) is on the shop\'s page, to read before answering, here or there: ' . $this->approvals->url($page);
        }

        return [
            'resultType' => 'input_required',
            'inputRequests' => [self::CONFIRM => [
                'method' => 'elicitation/create',
                'params' => [
                    'mode' => 'form',
                    'message' => "The assistant wants to: {$tool->title}. {$risk}\n\n{$what}",
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
     * The owner's yes for a client that cannot ask it: the call waits on the shop's approval page, whose link the
     * model is given to show the owner; once they said yes there, the same call runs, once. Null when it may run.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>|null
     */
    private function approvalPage(ToolDefinition $tool, array $arguments, Caller $caller, string $digest, bool $modern): ?array
    {
        $approval = $this->approvals->find($caller, $digest);
        if ($approval?->state === CallApproval::APPROVED) {
            // Claimed before it runs: of two retries sent at once, one runs and the other is told.
            return $this->approvals->spend($approval) ? null : self::toolResult(ToolOutcome::refused("The owner's yes was used already: the call ran once on it. If it should run again, call it again for a new yes."), $modern);
        }
        if ($approval?->state === CallApproval::REFUSED) {
            $this->approvals->spend($approval);

            return self::toolResult(ToolOutcome::refused('The owner said no: nothing was done.'), $modern);
        }
        $risk = self::RISK_WORDS[$tool->risk ?? ''] ?? '';
        $what = $this->what($tool, $arguments, $caller);
        $approval ??= $this->approvals->ask($caller, $digest, $tool->title, $risk, $what, self::ASK_TTL);

        return self::toolResult(ToolOutcome::refused(
            "{$tool->name} waits for the owner's yes (" . rtrim($risk, '.') . "), which this app cannot ask for. Give the owner this link, where they see what it would do and answer, logged in to the shop:\n"
            . $this->approvals->url($approval)
            . "\nWhen they say yes, call {$tool->name} again with the same arguments: it runs once. The link lasts " . intdiv(self::ASK_TTL, 60) . " minutes.\n\nWhat it would do:\n{$what}",
        ), $modern);
    }

    /**
     * What the call would do, where a description knows it; else what the tool does, with what it is given.
     *
     * @param array<string, mixed> $arguments
     */
    private function what(ToolDefinition $tool, array $arguments, Caller $caller): string
    {
        return $this->tools->describe($tool, $arguments, $caller)
            ?? $tool->description . ($arguments === [] ? '' : "\n\n" . json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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
