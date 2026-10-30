<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\Dto\ApproveTaxExemption;
use OrillaCart\Tax\Application\ExemptionCertificates;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The shop approves a certificate the customer sent, saying where it holds and until when: their orders into those
 * places are no longer taxed. {regions: ["US-TX"], expires_on: "2027-12-31"|null}
 */
#[Tool(risk: Tool::CUSTOMERS)]
#[Route('/customers/{id}/tax-exemptions/{exemption}/approve', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'exemption' => '\d+'])]
final readonly class ApproveTaxExemptionAction implements RequestHandlerInterface
{
    public function __construct(
        private ExemptionCertificates $certificates,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->certificates->approve(PathParameter::id($request), PathParameter::id($request, 'exemption'), $this->input->body($request, ApproveTaxExemption::class)));
    }
}
