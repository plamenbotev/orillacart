<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Http;

use OrillaCart\Agents\Application\UcpError;
use OrillaCart\Agents\Application\UcpService;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\CallerKeys;
use OrillaCart\Shared\Http\Security\ClientAddress;
use OrillaCart\Shared\Http\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * UCP's MCP binding (design 75): the same operations as tools (search_catalog, lookup_catalog, create_checkout,
 * get_checkout, update_checkout, complete_checkout, cancel_checkout), the agent's profile and idempotency key in
 * each call's `meta`. Stateless, as MCP allows: `initialize` is answered for clients that start with it, every
 * other request stands alone. A business outcome is a result; a protocol failure is error -32000 (-32001 for the
 * agent's profile), with UCP's code and words in its data.
 */
#[Route('/ucp/mcp', methods: ['POST'], capability: Route::PUBLIC, signed: true, changes: false)]
final readonly class UcpMcpAction implements RequestHandlerInterface
{
    /** MCP revisions with the initialize handshake, newest first, and the stateless one. */
    public const array REVISIONS = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'];

    public function __construct(
        private UcpService $ucp,
        private JsonResponder $responder,
        private RateLimiter $limits,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $message = json_decode((string) $request->getBody(), true);
        if (!\is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !\is_string($message['method'] ?? null)) {
            return $this->responder->exact(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'A JSON-RPC 2.0 request.']], 400);
        }
        $id = $message['id'] ?? null;
        if ($id === null) {
            // A notification: nothing is answered.
            return $this->responder->exact(null, 202);
        }
        if (!$this->ucp->on()) {
            return $this->responder->exact(self::error($id, -32000, 'not_found', 'The shop does not sell through agents.'), 404);
        }
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        return $this->responder->exact(match ($message['method']) {
            'initialize' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => \is_string($params['protocolVersion'] ?? null) && \in_array($params['protocolVersion'], self::REVISIONS, true) ? $params['protocolVersion'] : self::REVISIONS[1],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'orillacart-ucp', 'version' => '1.0.0'],
            ]],
            'ping' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => new \stdClass()],
            'tools/list' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => self::tools()]],
            'tools/call' => $this->call($id, $params, $request),
            default => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'No such method.']],
        });
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(mixed $id, array $params, ServerRequestInterface $request): array
    {
        $name = \is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $meta = \is_array($arguments['meta'] ?? null) ? $arguments['meta'] : [];
        unset($arguments['meta']);
        $agent = \is_array($meta['ucp-agent'] ?? null) && \is_string($meta['ucp-agent']['profile'] ?? null) ? $meta['ucp-agent']['profile'] : '';
        $key = \is_string($meta['idempotency-key'] ?? null) ? $meta['idempotency-key'] : null;
        if (!\in_array($name, array_column(self::tools(), 'name'), true)) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'No such tool.']];
        }
        try {
            if (\in_array($name, ['create_checkout', 'update_checkout', 'complete_checkout', 'cancel_checkout'], true)
                && $this->limits->hit(UcpRestAction::GUARD, CallerKeys::network('ip:' . ClientAddress::of($request->getServerParams()))) !== null) {
                throw new UcpError(429, 'rate_limited', 'That was a lot at once. Wait a little, then try again.');
            }
            $answer = $this->ucp->call($name, $arguments, $agent, $key, (string) json_encode($arguments));
        } catch (UcpError $e) {
            return self::error($id, \in_array($e->ucpCode, ['profile_unreachable', 'profile_malformed', 'version_unsupported', 'invalid_agent'], true) ? -32001 : -32000, $e->ucpCode, $e->getMessage());
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'content' => [['type' => 'text', 'text' => (string) json_encode($answer['body'], \JSON_UNESCAPED_SLASHES)]],
            'structuredContent' => $answer['body'],
            'isError' => false,
        ]];
    }

    /** @return array<string, mixed> */
    private static function error(mixed $id, int $code, string $ucpCode, string $content): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $content, 'data' => ['code' => $ucpCode, 'content' => $content]]];
    }

    /** @return list<array{name: string, description: string, inputSchema: array<string, mixed>}> */
    public static function tools(): array
    {
        $meta = ['type' => 'object', 'description' => 'ucp-agent: { profile: "https://…" }; idempotency-key for completing and cancelling'];
        $checkout = ['type' => 'object', 'description' => 'line_items [{ item: { id }, quantity }], buyer { email, first_name, last_name, phone_number }, fulfillment { methods: [{ type: "shipping", destinations: [{ id, street_address, address_locality, address_region, postal_code, address_country }], selected_destination_id, groups: [{ selected_option_id }] }] }'];
        $id = ['type' => 'string', 'description' => 'The checkout session, chk_…'];

        return [
            ['name' => 'search_catalog', 'description' => 'Products shoppers find: query, filters { categories, price { min, max } in minor units }, pagination { cursor, limit up to 24 }.', 'inputSchema' => ['type' => 'object', 'properties' => ['meta' => $meta, 'query' => ['type' => 'string'], 'filters' => ['type' => 'object'], 'pagination' => ['type' => 'object']], 'required' => ['meta']]],
            ['name' => 'lookup_catalog', 'description' => 'Products by their or their variants\' ids.', 'inputSchema' => ['type' => 'object', 'properties' => ['meta' => $meta, 'ids' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['meta', 'ids']]],
            ['name' => 'create_checkout', 'description' => 'A checkout session of the variants and quantities given; the buyer pays at the shop through its continue_url.', 'inputSchema' => ['type' => 'object', 'properties' => ['meta' => $meta, 'checkout' => $checkout], 'required' => ['meta', 'checkout']]],
            ['name' => 'get_checkout', 'description' => 'The checkout session as it is now.', 'inputSchema' => ['type' => 'object', 'properties' => ['meta' => $meta, 'id' => $id], 'required' => ['meta', 'id']]],
            ['name' => 'update_checkout', 'description' => 'The whole checkout session again: lines, buyer and fulfilment as given.', 'inputSchema' => ['type' => 'object', 'properties' => ['meta' => $meta, 'id' => $id, 'checkout' => $checkout], 'required' => ['meta', 'id', 'checkout']]],
            ['name' => 'complete_checkout', 'description' => 'The shop takes no delegated payment: this answers where the buyer completes it (continue_url).', 'inputSchema' => ['type' => 'object', 'properties' => ['meta' => $meta, 'id' => $id, 'checkout' => ['type' => 'object']], 'required' => ['meta', 'id']]],
            ['name' => 'cancel_checkout', 'description' => 'Ends the checkout session and empties its cart.', 'inputSchema' => ['type' => 'object', 'properties' => ['meta' => $meta, 'id' => $id], 'required' => ['meta', 'id']]],
        ];
    }
}
