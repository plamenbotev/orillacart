<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Http;

use OrillaCart\Assistant\Application\AssistantKeys;
use OrillaCart\Assistant\Application\Dto\AssistantChoices;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Chooses what the AI assistant that uses a key is offered, and which risks wait for the owner's yes. */
#[Tool(leftOut: 'API keys are managed by a shop manager logged in, never with a key.')]
#[Route('/api-keys/{id}/assistant', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ChooseKeyAssistantAction implements RequestHandlerInterface
{
    public function __construct(
        private AssistantKeys $keys,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->keys->choose(PathParameter::id($request), $this->input->body($request, AssistantChoices::class)));
    }
}
