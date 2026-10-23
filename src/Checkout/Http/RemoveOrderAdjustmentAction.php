<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Checkout\Application\OrderEditing;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Takes a change to the price off an order on hold; one for the whole order goes from every item. */
#[Route('/orders/{id}/adjustments/{change}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'change' => '[0-9a-f]{16}'])]
final readonly class RemoveOrderAdjustmentAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderEditing $editing,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->editing->removeAdjustment(PathParameter::id($request), PathParameter::string($request, 'change'), $this->authorization->userId()));
    }
}
