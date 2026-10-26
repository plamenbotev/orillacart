<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shipping\Application\CarrierService;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Changes a carrier: its name, whether checkout offers it, its position, and its whole list of rates. A secret method
 * setting left empty keeps the saved one.
 */
#[Route('/carriers/{id}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class UpdateCarrierAction implements RequestHandlerInterface
{
    public function __construct(
        private CarrierService $carriers,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->carriers->update(PathParameter::id($request), $this->input->body($request, SaveCarrier::class)));
    }
}
