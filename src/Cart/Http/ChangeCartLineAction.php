<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Http;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\ChangeQuantity;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/cart/items/{id}', methods: ['PUT'], capability: Route::PUBLIC, requirements: ['id' => '\d+'])]
final readonly class ChangeCartLineAction implements RequestHandlerInterface
{
    public function __construct(
        private CartService $carts,
        private CartCookie $cookie,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->carts->changeQuantity($this->cookie->owner($request), PathParameter::id($request), $this->input->body($request, ChangeQuantity::class));

        return $this->cookie->give($this->responder->ok($result->cart), $result->newToken, $request);
    }
}
