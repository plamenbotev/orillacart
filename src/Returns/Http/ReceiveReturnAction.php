<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Http;

use OrillaCart\Returns\Application\Returns;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The returned items came back to the shop.
 */
#[Route('/returns/{id}/receive', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ReceiveReturnAction implements RequestHandlerInterface
{
    public function __construct(
        private Returns $returns,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->returns->receive(PathParameter::id($request), $this->authorization->userId()));
    }
}
