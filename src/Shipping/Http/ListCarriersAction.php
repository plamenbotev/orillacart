<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Http;

use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shipping\Application\CarrierService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Lists the carriers in checkout order, with their rates; nothing changes. */
#[Route('/carriers', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListCarriersAction implements RequestHandlerInterface
{
    public function __construct(
        private CarrierService $carriers,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->carriers->carriers());
    }
}
