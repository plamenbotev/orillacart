<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Http;

use OrillaCart\Setup\Application\SetupService;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** What the answers would change, in words, with what there is already; nothing is saved. */
#[Route('/setup/plan', methods: ['POST'], capability: Capability::MANAGE_SHOP, changes: false)]
final readonly class PlanSetupAction implements RequestHandlerInterface
{
    public function __construct(
        private SetupService $setup,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->setup->plan());
    }
}
