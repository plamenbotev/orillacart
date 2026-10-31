<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Http;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Storefront\Application\PromotionsHtml;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The promotions the shopper's cart gets and what it still needs for others, a lower price by quantity among them,
 * as the Promotion progress block shows them ({html}, empty for nothing to say): the storefront script fills the
 * block in with it on cached pages, and as the cart changes.
 */
#[Route('/cart/promotions', methods: ['GET'], capability: Route::PUBLIC)]
final readonly class ShowPromotionsAction implements RequestHandlerInterface
{
    public function __construct(
        private PromotionsHtml $promotions,
        private CartCookie $cookie,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Per browser: never from a shared cache.
        return $this->responder->ok(['html' => $this->promotions->render($this->cookie->owner($request))])->withHeader('Cache-Control', 'no-store, private');
    }
}
