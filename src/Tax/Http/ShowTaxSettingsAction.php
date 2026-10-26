<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\TaxSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Shows how the shop taxes: by which address, whether prices include tax, the default group, a tax service, and how
 * tax is shown.
 */
#[Route('/tax/settings', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowTaxSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private TaxSettingsService $settings,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->settings->current());
    }
}
