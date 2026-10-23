<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\ExemptionCertificates;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The signed-in customer's tax exemption certificates, and whether the shop takes them online. */
#[Route('/account/tax-exemptions', methods: ['GET'], capability: Capability::ACCOUNT)]
final readonly class ListAccountTaxExemptionsAction implements RequestHandlerInterface
{
    public function __construct(
        private ExemptionCertificates $certificates,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->authorization->userId() ?? throw HttpException::unauthorized();

        return $this->responder->ok(['accepts' => $this->certificates->accepts(), 'exemptions' => $this->certificates->mine($id)]);
    }
}
