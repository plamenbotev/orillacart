<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shipping\Application\Dto\SaveShippingClass;
use OrillaCart\Shipping\Application\ShippingClassService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Renames a shipping class. */
#[Route('/shipping-classes/{id}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class RenameShippingClassAction implements RequestHandlerInterface
{
    public function __construct(
        private ShippingClassService $classes,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->classes->rename(PathParameter::id($request), $this->input->body($request, SaveShippingClass::class)));
    }
}
