<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Http;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/cart/items/{id}', methods: ['DELETE'], capability: Route::PUBLIC, requirements: ['id' => '\d+'])]
final readonly class RemoveCartLineAction implements RequestHandlerInterface
{
    public function __construct(
        private CartService $carts,
        private CartCookie $cookie,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->carts->remove($this->cookie->owner($request), PathParameter::id($request));

        return $this->cookie->keep($this->responder->ok($result->cart), $result, $request);
    }
}
