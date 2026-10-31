<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Http;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Storefront\Application\FreeShippingHtml;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * How far the shopper's cart is from free shipping, as the Free shipping progress block shows it ({html}, empty
 * for nothing to say): the storefront script fills the block in with it on cached pages, and as the cart changes.
 */
#[Route('/cart/free-shipping', methods: ['GET'], capability: Route::PUBLIC)]
final readonly class ShowFreeShippingAction implements RequestHandlerInterface
{
    public function __construct(
        private FreeShippingHtml $progress,
        private CartCookie $cookie,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Per browser: never from a shared cache.
        return $this->responder->ok(['html' => $this->progress->render($this->cookie->owner($request))])->withHeader('Cache-Control', 'no-store, private');
    }
}
