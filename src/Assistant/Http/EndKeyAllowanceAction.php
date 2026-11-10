<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Http;

use OrillaCart\Assistant\Application\AssistantKeys;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The AI assistant that uses a key asks again before the tool named, or before every tool when none is: what it still runs unasked. */
#[Tool(leftOut: 'API keys are managed by a shop manager logged in, never with a key.')]
#[Route('/api-keys/{id}/assistant/allowances', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
#[Route('/api-keys/{id}/assistant/allowances/{tool}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'tool' => '[a-z][a-z0-9_]{0,63}'])]
final readonly class EndKeyAllowanceAction implements RequestHandlerInterface
{
    public function __construct(
        private AssistantKeys $keys,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tool = $request->getAttribute('tool');

        return $this->responder->ok(['allowances' => ListKeyAllowancesAction::listed($this->keys->ask(PathParameter::id($request), \is_string($tool) ? $tool : null, $this->authorization->userId() ?? 0, $this->authorization->isGranted('manage_options')))]);
    }
}
