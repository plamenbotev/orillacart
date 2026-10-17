<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Http;

use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/settings', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class UpdateSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private SettingsService $settings,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->settings->update($this->input->body($request, UpdateSettings::class)));
    }
}
