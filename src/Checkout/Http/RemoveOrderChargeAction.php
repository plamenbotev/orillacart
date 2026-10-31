<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Checkout\Application\OrderEditing;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Takes a charge the shop added off an order on hold, by the line's place in the order; an add-on's stays. */
#[Tool(risk: Tool::MONEY)]
#[Route('/orders/{id}/charges/{line}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'line' => '\d+'])]
final readonly class RemoveOrderChargeAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderEditing $editing,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->editing->removeCharge(PathParameter::id($request), PathParameter::id($request, 'line'), $this->authorization->userId()));
    }
}
