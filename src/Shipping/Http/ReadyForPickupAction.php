<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shipping\Application\Consignments;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tells the customer the order is ready at the shop's place they collect it from.
 */
#[Route('/orders/{id}/ready-for-pickup', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ReadyForPickupAction implements RequestHandlerInterface
{
    public function __construct(
        private Consignments $consignments,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->consignments->readyForPickup(PathParameter::id($request), $this->authorization->userId());

        return $this->responder->noContent();
    }
}
