<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\ExemptionCertificates;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The shop approves a certificate the customer sent: their orders into its states are no longer taxed. */
#[Route('/customers/{id}/tax-exemptions/{exemption}/approve', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'exemption' => '\d+'])]
final readonly class ApproveTaxExemptionAction implements RequestHandlerInterface
{
    public function __construct(
        private ExemptionCertificates $certificates,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->certificates->approve(PathParameter::id($request), PathParameter::id($request, 'exemption')));
    }
}
