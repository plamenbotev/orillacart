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

/**
 * The shopper wants the free items a discount gives after all, having removed them: the cart is given them again,
 * while the discount holds.
 */
#[Route('/cart/given/{code}', methods: ['POST'], capability: Route::PUBLIC, requirements: ['code' => '[^/]{1,40}'])]
final readonly class RestoreGivenAction implements RequestHandlerInterface
{
    public function __construct(
        private CartService $carts,
        private CartCookie $cookie,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->carts->restoreGiven($this->cookie->owner($request), PathParameter::string($request, 'code'));

        return $this->cookie->keep($this->responder->ok($result->cart), $result, $request);
    }
}
