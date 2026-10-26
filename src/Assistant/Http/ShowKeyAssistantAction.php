<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Http;

use OrillaCart\Assistant\Application\AssistantKeys;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** What the owner chose for the AI assistant that uses a key: its areas and tools, how they are listed, what it asks first. */
#[Tool(leftOut: 'API keys are managed by a shop manager logged in, never with a key.')]
#[Route('/api-keys/{id}/assistant', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ShowKeyAssistantAction implements RequestHandlerInterface
{
    public function __construct(
        private AssistantKeys $keys,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->keys->choices(PathParameter::id($request)));
    }
}
