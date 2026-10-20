<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Http;

use OrillaCart\Returns\Application\Returns;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * An order's returns, and what may still go back.
 */
#[Route('/orders/{id}/returns', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ListOrderReturnsAction implements RequestHandlerInterface
{
    public function __construct(
        private Returns $returns,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->returns->forOrder(PathParameter::id($request)));
    }
}
