<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Http;

use OrillaCart\Integrations\Application\Webhooks;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A webhook's test (POST …/ping: a webhook.ping event, sent by the background jobs), its new secret
 * (POST …/secret), and the tries at delivering to it (GET …/deliveries, newest first).
 */
#[Route('/webhooks/{id}/ping', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
#[Route('/webhooks/{id}/secret', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
#[Route('/webhooks/{id}/deliveries', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class WebhookActionsAction implements RequestHandlerInterface
{
    public function __construct(
        private Webhooks $webhooks,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = PathParameter::id($request);
        $path = $request->getUri()->getPath();

        return match (true) {
            str_ends_with($path, '/ping') => $this->ping($id),
            str_ends_with($path, '/secret') => $this->responder->ok($this->webhooks->newSecret($id)),
            default => $this->responder->ok($this->webhooks->deliveries($id)),
        };
    }

    private function ping(int $id): ResponseInterface
    {
        $this->webhooks->ping($id);

        return $this->responder->ok(['queued' => true], 202);
    }
}
