<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Http;

use OrillaCart\Integrations\Application\ApiKeys;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Tool(leftOut: 'API keys are made and revoked by a shop manager logged in, never with a key.')]
#[Route('/api-keys', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListApiKeysAction implements RequestHandlerInterface
{
    public function __construct(
        private ApiKeys $keys,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->keys->all());
    }
}
