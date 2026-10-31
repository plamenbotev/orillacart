<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Http;

use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Storefront\Application\StorefrontSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Shows the storefront's settings: product data for search engines, sharing tags, the wishlist, recently viewed
 * products, the data layer, filters, and the store notice with its translations.
 */
#[Route('/storefront-settings', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowStorefrontSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private StorefrontSettingsService $settings,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->settings->settings());
    }
}
