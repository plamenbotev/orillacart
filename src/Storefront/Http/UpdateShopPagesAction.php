<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Http;

use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Storefront\Application\Dto\UpdateShopPages;
use OrillaCart\Storefront\Application\ShopPagesService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sets which published pages the shop, cart, checkout and account are on; none for a page leaves that part without
 * one.
 */
#[Route('/shop-pages', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class UpdateShopPagesAction implements RequestHandlerInterface
{
    public function __construct(
        private ShopPagesService $pages,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->pages->update($this->input->body($request, UpdateShopPages::class)));
    }
}
