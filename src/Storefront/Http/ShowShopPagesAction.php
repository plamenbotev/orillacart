<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Http;

use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Storefront\Application\ShopPagesService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/shop-pages', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowShopPagesAction implements RequestHandlerInterface
{
    public function __construct(
        private ShopPagesService $pages,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->pages->pages());
    }
}
