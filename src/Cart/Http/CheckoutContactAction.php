<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Http;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CheckoutContact;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Lifecycle\Languages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The checkout page tells of the customer at checkout with their cart: the email so far, and whether they ask to
 * be reminded of the cart if they leave it.
 */
#[Route('/cart/checkout-contact', methods: ['POST'], capability: Route::PUBLIC)]
final readonly class CheckoutContactAction implements RequestHandlerInterface
{
    public function __construct(
        private CartService $carts,
        private CartCookie $cookie,
        private RequestMapper $input,
        private JsonResponder $responder,
        private Languages $languages,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->carts->atCheckout($this->cookie->owner($request), $this->input->body($request, CheckoutContact::class), $this->languages->current());

        return $this->cookie->give($this->responder->noContent(), $token, $request);
    }
}
