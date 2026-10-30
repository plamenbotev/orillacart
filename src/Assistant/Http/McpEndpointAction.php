<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Http;

use OrillaCart\Assistant\Application\AssistantRequests;
use OrillaCart\Assistant\Application\McpServer;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The shop's MCP server for AI assistants: one JSON-RPC message per POST, answered with JSON. Its tools are the
 * shop managers' own, acting as the manager whose API key (or login) called; each tool's change is logged by the
 * route it runs, so this call itself is not.
 */
#[Tool(leftOut: 'The assistant\'s own endpoint.')]
#[Route('/mcp', methods: ['POST'], capability: Capability::MANAGE_SHOP, changes: false)]
final readonly class McpEndpointAction implements RequestHandlerInterface
{
    /** Keys whose value is a JSON object wherever it is, empty or not. */
    private const array OBJECTS = ['capabilities', 'properties', 'annotations', '_meta', 'inputSchema', 'outputSchema', 'requestedSchema', '$defs', 'additionalProperties', 'serverInfo'];

    public function __construct(
        private McpServer $server,
        private AssistantRequests $requests,
        private JsonResponder $responder,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && !$this->requests->allowsOrigin($origin)) {
            return $this->responder->exact(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'This origin may not call the shop\'s MCP server.']], 403);
        }
        $caller = $this->requests->caller();
        if ($caller === null) {
            throw new HttpException(401, 'orillacart_not_logged_in', 'Connect with an API key from Shop → Integrations.');
        }
        $body = (string) $request->getBody();
        $sent = (int) $request->getHeaderLine('Content-Length');
        if ($body === '' && $sent > 0) {
            // PHP drops a body larger than post_max_size before the shop sees it.
            return $this->responder->exact(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => \sprintf(
                'The request (%s) is larger than this site takes (%s, PHP\'s post_max_size), so the shop never saw it. Send a large file as file_url, an address the shop fetches, not as file_base64.',
                self::megabytes($sent),
                self::megabytes(self::bytes((string) \ini_get('post_max_size'))),
            )]], 413);
        }
        $message = $body === '' ? null : json_decode($body, true);
        $headers = [];
        foreach (['mcp-protocol-version', 'mcp-method', 'mcp-name'] as $name) {
            if ($request->hasHeader($name)) {
                $headers[$name] = trim($request->getHeaderLine($name));
            }
        }

        $reply = $this->server->handle($message, $headers, $caller);
        if ($reply->body === null) {
            return $this->responses->createResponse($reply->status);
        }

        return $this->responder->exact(self::objects($reply->body), $reply->status);
    }

    /** PHP's "8M" as bytes. */
    public static function bytes(string $setting): int
    {
        $setting = trim($setting);
        $number = (int) $setting;

        return match (strtolower(substr($setting, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private static function megabytes(int $bytes): string
    {
        return $bytes <= 0 ? 'no limit' : round($bytes / 1024 ** 2, 1) . ' MB';
    }

    /**
     * The reply with the JSON objects the protocol needs kept as objects where they are empty: PHP has one empty
     * array for both, so an empty result, the capabilities and what each offers, and a schema's properties,
     * annotations and _meta become {}. Every other empty array stays a list: tools/list with no tools answers
     * "tools": [], and what a tool answered (structuredContent, content) is passed on as it is.
     *
     * @param list<string> $path the keys down to the value; "#" for a list's item
     */
    public static function objects(mixed $value, array $path = []): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if ($path !== [] && $path[0] === 'result' && \in_array($path[1] ?? null, ['structuredContent', 'content'], true)) {
            return $value;
        }
        if ($value === []) {
            $key = $path === [] ? '' : $path[\count($path) - 1];
            $parent = $path[\count($path) - 2] ?? null;

            return $path === ['result'] || $parent === 'capabilities' || \in_array($key, self::OBJECTS, true) ? new \stdClass() : [];
        }
        $out = [];
        foreach ($value as $name => $item) {
            $out[$name] = self::objects($item, [...$path, \is_string($name) ? $name : '#']);
        }

        return $out;
    }
}
