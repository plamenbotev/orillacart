<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Http;

use OrillaCart\Reports\Application\EconomicNexus;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The shop's sales in each US state against its economic nexus thresholds. */
#[Route('/reports/nexus', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowNexusReportAction implements RequestHandlerInterface
{
    public function __construct(
        private EconomicNexus $nexus,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->nexus->report());
    }
}
