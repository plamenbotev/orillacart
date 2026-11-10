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

/** The tools the owner let the AI assistant that uses a key run without asking, each until when, the soonest to run out first. */
#[Tool(leftOut: 'API keys are managed by a shop manager logged in, never with a key.')]
#[Route('/api-keys/{id}/assistant/allowances', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ListKeyAllowancesAction implements RequestHandlerInterface
{
    public function __construct(
        private AssistantKeys $keys,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok(['allowances' => self::listed($this->keys->allowances(PathParameter::id($request), $this->authorization->userId() ?? 0, $this->authorization->isGranted('manage_options')))]);
    }

    /**
     * @param array<string, int> $allowed
     *
     * @return list<array{tool: string, until: string}>
     */
    public static function listed(array $allowed): array
    {
        $listed = [];
        foreach ($allowed as $tool => $until) {
            $listed[] = ['tool' => $tool, 'until' => gmdate(\DATE_ATOM, $until)];
        }

        return $listed;
    }
}
