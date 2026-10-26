<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Http;

use OrillaCart\Integrations\Application\ApiKeys;
use OrillaCart\Integrations\Application\Dto\CreateApiKey;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A new key, acting as the shop manager who makes it; the answer holds the key, shown this once. */
#[Tool(leftOut: 'API keys are made and revoked by a shop manager logged in, never with a key.')]
#[Route('/api-keys', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreateApiKeyAction implements RequestHandlerInterface
{
    public function __construct(
        private ApiKeys $keys,
        private Authorization $authorization,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->authorization->userId() ?? throw HttpException::unauthorized();

        return $this->responder->ok($this->keys->create($this->input->body($request, CreateApiKey::class), $userId), 201);
    }
}
