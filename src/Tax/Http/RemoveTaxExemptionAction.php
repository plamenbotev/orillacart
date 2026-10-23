<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\TaxExemptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Removes a customer's exemption; orders it covered keep it. */
#[Route('/customers/{id}/tax-exemptions/{exemption}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'exemption' => '\d+'])]
final readonly class RemoveTaxExemptionAction implements RequestHandlerInterface
{
    public function __construct(
        private TaxExemptions $exemptions,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->exemptions->remove(PathParameter::id($request), PathParameter::id($request, 'exemption'));

        return $this->responder->noContent();
    }
}
