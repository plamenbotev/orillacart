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

    /**
     * The reply with the JSON objects the protocol needs kept as objects: schemas, results and arguments decoded
     * from PHP arrays come back as {}, not [], where they are empty.
     */
    private static function objects(mixed $value, string $key = ''): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if ($value === []) {
            return \in_array($key, ['result', 'properties', 'capabilities', 'tools', 'annotations', 'structuredContent', '_meta', 'arguments', 'additionalProperties'], true) ? new \stdClass() : [];
        }
        $out = [];
        foreach ($value as $name => $item) {
            $out[$name] = self::objects($item, \is_string($name) ? $name : $key);
        }

        return $out;
    }
}
