<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\Dto\RefuseTaxExemption;
use OrillaCart\Tax\Application\ExemptionCertificates;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The shop refuses a certificate the customer sent, and tells them why. */
#[Tool(risk: Tool::CUSTOMERS)]
#[Route('/customers/{id}/tax-exemptions/{exemption}/refuse', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'exemption' => '\d+'])]
final readonly class RefuseTaxExemptionAction implements RequestHandlerInterface
{
    public function __construct(
        private ExemptionCertificates $certificates,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->certificates->refuse(PathParameter::id($request), PathParameter::id($request, 'exemption'), $this->input->body($request, RefuseTaxExemption::class)->reason));
    }
}
