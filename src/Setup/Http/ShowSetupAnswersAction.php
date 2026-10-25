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

/** The setup's answers so far, by step: null for a step not answered yet. */
#[Route('/setup/answers', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowSetupAnswersAction implements RequestHandlerInterface
{
    public function __construct(
        private SetupService $setup,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->setup->answers());
    }
}
