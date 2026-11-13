<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Http;

use OrillaCart\Agents\Application\UcpError;
use OrillaCart\Agents\Application\UcpService;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Security\CallerKeys;
use OrillaCart\Shared\Http\Security\ClientAddress;
use OrillaCart\Shared\Http\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * UCP's REST binding (design 75): the catalogue's search and lookup, and checkout sessions. Agents call it server
 * to server, as payment providers call their webhooks, so it carries no nonce (signed: the action checks what
 * it needs), and it acts for no one: a session is its own cart, never the caller's cookies' or account's. Every
 * change names an Idempotency-Key and is counted per caller; reads are not counted.
 */
#[Route('/ucp/catalog/search', methods: ['POST'], capability: Route::PUBLIC, signed: true, changes: false)]
#[Route('/ucp/catalog/lookup', methods: ['POST'], capability: Route::PUBLIC, signed: true, changes: false)]
#[Route('/ucp/checkout-sessions', methods: ['POST'], capability: Route::PUBLIC, signed: true, changes: false)]
#[Route('/ucp/checkout-sessions/{id}', methods: ['GET', 'PUT'], capability: Route::PUBLIC, requirements: ['id' => 'chk_[a-f0-9]{32}'], signed: true, changes: false)]
#[Route('/ucp/checkout-sessions/{id}/complete', methods: ['POST'], capability: Route::PUBLIC, requirements: ['id' => 'chk_[a-f0-9]{32}'], signed: true, changes: false)]
#[Route('/ucp/checkout-sessions/{id}/cancel', methods: ['POST'], capability: Route::PUBLIC, requirements: ['id' => 'chk_[a-f0-9]{32}'], signed: true, changes: false)]
final readonly class UcpRestAction implements RequestHandlerInterface
{
    /** Agents' changes per caller: as many as two carts' worth a second, over ten minutes (TransientRateLimiter). */
    public const string GUARD = 'ucp';

    public function __construct(
        private UcpService $ucp,
        private JsonResponder $responder,
        private RateLimiter $limits,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = RouteDefinition::fromRequest($request);
        $method = $request->getMethod();
        $id = $request->getAttribute('id');
        $operation = match (true) {
            $route->path === '/ucp/catalog/search' => 'search_catalog',
            $route->path === '/ucp/catalog/lookup' => 'lookup_catalog',
            $route->path === '/ucp/checkout-sessions' => 'create_checkout',
            str_ends_with($route->path, '/complete') => 'complete_checkout',
            str_ends_with($route->path, '/cancel') => 'cancel_checkout',
            $method === 'PUT' => 'update_checkout',
            default => 'get_checkout',
        };
        $raw = (string) $request->getBody();
        $requestId = $request->getHeaderLine('Request-Id');
        try {
            if (!$this->ucp->on()) {
                throw new UcpError(404, 'not_found', 'The shop does not sell through agents.');
            }
            $changes = \in_array($operation, ['create_checkout', 'update_checkout', 'complete_checkout', 'cancel_checkout'], true);
            $key = $request->getHeaderLine('Idempotency-Key');
            if ($changes && $key === '') {
                throw new UcpError(400, 'idempotency_key_required', 'A change carries an Idempotency-Key.');
            }
            if ($changes && ($wait = $this->limits->hit(self::GUARD, CallerKeys::network('ip:' . ClientAddress::of($request->getServerParams())))) !== null) {
                return $this->failed(new UcpError(429, 'rate_limited', 'That was a lot at once. Wait a little, then try again.'), $requestId)->withHeader('Retry-After', (string) $wait);
            }
            $arguments = $raw === '' ? [] : json_decode($raw, true);
            if (!\is_array($arguments)) {
                throw new UcpError(400, 'invalid_request', 'The body is a JSON object.');
            }
            if (\is_string($id)) {
                $arguments = ['id' => $id, 'checkout' => $arguments];
            }
            $answer = $this->ucp->call($operation, $arguments, self::agent($request->getHeaderLine('UCP-Agent')), $changes ? $key : null, $raw);
        } catch (UcpError $e) {
            return $this->failed($e, $requestId);
        }
        $response = $this->responder->exact($answer['body'], $answer['status']);

        return $requestId === '' ? $response : $response->withHeader('Request-Id', mb_substr($requestId, 0, 200));
    }

    /** The profile URL in `profile="https://…"` (RFC 8941 dictionary). */
    public static function agent(string $header): string
    {
        return preg_match('/(?:^|[;,\s])profile\s*=\s*"([^"]{1,500})"/', $header, $match) === 1 ? $match[1] : '';
    }

    private function failed(UcpError $e, string $requestId): ResponseInterface
    {
        $response = $this->responder->exact(['code' => $e->ucpCode, 'content' => $e->getMessage()], $e->status);

        return $requestId === '' ? $response : $response->withHeader('Request-Id', mb_substr($requestId, 0, 200));
    }
}
