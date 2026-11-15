<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Http;

use OrillaCart\Settings\Application\Dto\UpdateLegalSettings;
use OrillaCart\Settings\Application\LegalSettingsService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Sets the shop's legal pages, whether checkout asks to accept the terms, and what its last button says. */
#[Tool(risk: Tool::PUBLISHING, area: 'Storefront')]
#[Route('/legal-settings', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class UpdateLegalSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private LegalSettingsService $settings,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->settings->update($this->input->body($request, UpdateLegalSettings::class)));
    }
}
