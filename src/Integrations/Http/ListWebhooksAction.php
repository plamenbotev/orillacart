<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Http;

use OrillaCart\Integrations\Application\Webhooks;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The webhooks, and the topics they can listen to. */
#[Tool(leftOut: 'Webhooks are managed by a shop manager logged in: their secrets sign what the shop sends.')]
#[Route('/webhooks', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListWebhooksAction implements RequestHandlerInterface
{
    public function __construct(
        private Webhooks $webhooks,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok(['webhooks' => $this->webhooks->all(), 'topics' => $this->webhooks->topics()]);
    }
}
