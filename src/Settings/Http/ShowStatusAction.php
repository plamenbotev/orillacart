<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Http;

use OrillaCart\Settings\Application\StatusReport;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/status', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowStatusAction implements RequestHandlerInterface
{
    public function __construct(
        private StatusReport $status,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->status->report());
    }
}
