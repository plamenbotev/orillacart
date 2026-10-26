<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Http;

use OrillaCart\Setup\Application\Dto\ApplySetup;
use OrillaCart\Setup\Application\SetupService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Makes the plan the owner saw, replacing only what they chose to; the settings from before are kept. */
#[Tool(risk: Tool::OPENING)]
#[Route('/setup/apply', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class ApplySetupAction implements RequestHandlerInterface
{
    public function __construct(
        private SetupService $setup,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->setup->apply($this->input->body($request, ApplySetup::class)));
    }
}
