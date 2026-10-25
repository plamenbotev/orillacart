<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Http;

use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Storefront\Application\LegalSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The shop's legal pages, and whether checkout asks to accept the terms and what its order button says. */
#[Route('/legal-settings', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowLegalSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private LegalSettingsService $settings,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->settings->current());
    }
}
