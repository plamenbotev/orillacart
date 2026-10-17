<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Http;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/cart', methods: ['GET'], capability: Route::PUBLIC)]
final readonly class ShowCartAction implements RequestHandlerInterface
{
    public function __construct(
        private CartService $carts,
        private CartCookie $cookie,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->carts->cart($this->cookie->owner($request));

        // Per browser: never from a shared cache. The storefront script reads it to correct cached pages' cart counts.
        return $this->cookie->give($this->responder->ok($result->cart)->withHeader('Cache-Control', 'no-store, private'), $result->newToken, $request);
    }
}
