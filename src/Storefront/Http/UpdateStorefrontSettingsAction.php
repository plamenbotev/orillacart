<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Http;

use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Storefront\Application\Dto\UpdateStorefrontSettings;
use OrillaCart\Storefront\Application\StorefrontSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Replaces the storefront's settings: product data for search engines, sharing tags, who may keep a wishlist, recently
 * viewed products, the data layer, filters, and the store notice shoppers read over the shop's pages.
 */
#[Route('/storefront-settings', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
#[Tool(risk: Tool::PUBLISHING)]
final readonly class UpdateStorefrontSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private StorefrontSettingsService $settings,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->settings->update($this->input->body($request, UpdateStorefrontSettings::class)));
    }
}
