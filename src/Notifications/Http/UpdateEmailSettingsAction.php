<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Http;

use OrillaCart\Notifications\Application\Dto\UpdateEmailSettings;
use OrillaCart\Notifications\Application\EmailSettingsService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/email-settings', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class UpdateEmailSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private EmailSettingsService $settings,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->settings->update($this->input->body($request, UpdateEmailSettings::class)));
    }
}
