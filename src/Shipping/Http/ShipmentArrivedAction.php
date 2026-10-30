<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shipping\Application\Consignments;
use OrillaCart\Shipping\Application\Dto\ShipmentArrived;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A shipment the shop sent itself arrived, or came back.
 */
#[Tool(risk: Tool::CUSTOMERS)]
#[Route('/shipments/{id}/arrived', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ShipmentArrivedAction implements RequestHandlerInterface
{
    public function __construct(
        private Consignments $consignments,
        private RequestMapper $input,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->consignments->arrived(PathParameter::id($request), $this->input->body($request, ShipmentArrived::class), $this->authorization->userId()));
    }
}
