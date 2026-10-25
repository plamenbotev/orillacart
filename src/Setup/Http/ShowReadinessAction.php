<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Http;

use OrillaCart\Setup\Application\ShopReadiness;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Whether the shop is ready to sell: what stops it, then what is advised. */
#[Route('/setup/readiness', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowReadinessAction implements RequestHandlerInterface
{
    public function __construct(
        private ShopReadiness $readiness,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->readiness->report());
    }
}
