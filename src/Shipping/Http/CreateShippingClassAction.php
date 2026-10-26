<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Http;

use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shipping\Application\Dto\SaveShippingClass;
use OrillaCart\Shipping\Application\ShippingClassService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Adds a shipping class, which products are put in and rates can be limited to. */
#[Route('/shipping-classes', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreateShippingClassAction implements RequestHandlerInterface
{
    public function __construct(
        private ShippingClassService $classes,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->classes->create($this->input->body($request, SaveShippingClass::class)), 201);
    }
}
