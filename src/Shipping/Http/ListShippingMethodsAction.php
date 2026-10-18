<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Http;

use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shipping\Application\ShippingMethods;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The shipping methods add-ons registered, which a carrier can be priced with, with their settings.
 */
#[Route('/shipping-methods', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListShippingMethodsAction implements RequestHandlerInterface
{
    public function __construct(
        private ShippingMethods $methods,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->methods->methods());
    }
}
