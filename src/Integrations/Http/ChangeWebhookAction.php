<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Http;

use OrillaCart\Integrations\Application\Dto\SaveWebhook;
use OrillaCart\Integrations\Application\Webhooks;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Changes a webhook, or deletes it with what it delivered. */
#[Route('/webhooks/{id}', methods: ['PUT', 'DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ChangeWebhookAction implements RequestHandlerInterface
{
    public function __construct(
        private Webhooks $webhooks,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = PathParameter::id($request);
        if ($request->getMethod() === 'DELETE') {
            $this->webhooks->delete($id);

            return $this->responder->noContent();
        }

        return $this->responder->ok($this->webhooks->update($id, $this->input->body($request, SaveWebhook::class)));
    }
}
