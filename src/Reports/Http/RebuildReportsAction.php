<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Http;

use OrillaCart\Reports\Application\Reports;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Counts every order again, in the background.
 */
#[Route('/reports/rebuild', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class RebuildReportsAction implements RequestHandlerInterface
{
    public function __construct(
        private Reports $reports,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->reports->rebuild();

        return $this->responder->ok(['queued' => true], 202);
    }
}
