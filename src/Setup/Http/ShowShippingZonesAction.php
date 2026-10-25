<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Http;

use OrillaCart\Setup\Application\SetupService;
use OrillaCart\Setup\Application\Step\ShippingStep;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The zones the shipping step prices, from where the answers so far say the shop sells. */
#[Route('/setup/shipping-zones', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowShippingZonesAction implements RequestHandlerInterface
{
    public function __construct(
        private SetupService $setup,
        private ShippingStep $shipping,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->setup->shippingZones($this->shipping));
    }
}
