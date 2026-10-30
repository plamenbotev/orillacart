<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shipping\Application\Consignments;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The customer collected the order from the shop's place: it is completed.
 */
#[Tool(risk: Tool::CUSTOMERS)]
#[Route('/orders/{id}/picked-up', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class PickedUpAction implements RequestHandlerInterface
{
    public function __construct(
        private Consignments $consignments,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->consignments->pickedUp(PathParameter::id($request), $this->authorization->userId());

        return $this->responder->noContent();
    }
}
