<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Http;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/cart/items', methods: ['POST'], capability: Route::PUBLIC)]
final readonly class AddToCartAction implements RequestHandlerInterface
{
    public function __construct(
        private CartService $carts,
        private CartCookie $cookie,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->carts->add($this->cookie->owner($request), $this->input->body($request, AddToCart::class));

        return $this->cookie->keep($this->responder->ok($result->cart, 201), $result, $request);
    }
}
