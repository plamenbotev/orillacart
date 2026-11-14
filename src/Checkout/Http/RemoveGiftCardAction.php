<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Checkout\Application\CheckoutCodes;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/cart/gift-cards/{code}', methods: ['DELETE'], capability: Route::PUBLIC, requirements: ['code' => '[^/]{1,40}'], guard: 'checkout_summary')]
final readonly class RemoveGiftCardAction implements RequestHandlerInterface
{
    public function __construct(
        private CheckoutCodes $codes,
        private CartCookie $cookie,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->codes->removeGiftCard($this->cookie->owner($request), PathParameter::string($request, 'code')));
    }
}
