<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\Dto\UpdateTaxSettings;
use OrillaCart\Tax\Application\TaxSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Replaces the tax settings: by which address the shop taxes, whether prices include tax, the default group, a tax
 * service, and how tax is shown.
 */
#[Route('/tax/settings', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class UpdateTaxSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private TaxSettingsService $settings,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->settings->update($this->input->body($request, UpdateTaxSettings::class)));
    }
}
