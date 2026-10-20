<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shipping\Application\Consignments;
use OrillaCart\Shipping\Application\Dto\SendShipment;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records a shipment the shop sent itself, with each parcel's tracking number.
 */
#[Route('/orders/{id}/shipments/sent', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class SendShipmentAction implements RequestHandlerInterface
{
    public function __construct(
        private Consignments $consignments,
        private RequestMapper $input,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->consignments->send(PathParameter::id($request), $this->input->body($request, SendShipment::class), $this->authorization->userId()), 201);
    }
}
