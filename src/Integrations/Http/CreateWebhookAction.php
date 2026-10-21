<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Http;

use OrillaCart\Integrations\Application\Dto\SaveWebhook;
use OrillaCart\Integrations\Application\Webhooks;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/webhooks', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreateWebhookAction implements RequestHandlerInterface
{
    public function __construct(
        private Webhooks $webhooks,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->webhooks->create($this->input->body($request, SaveWebhook::class)), 201);
    }
}
