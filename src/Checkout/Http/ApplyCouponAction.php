<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Checkout\Application\CheckoutService;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/cart/coupons', methods: ['POST'], capability: Route::PUBLIC)]
final readonly class ApplyCouponAction implements RequestHandlerInterface
{
    public function __construct(
        private CheckoutService $checkout,
        private CartCookie $cookie,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->checkout->applyCoupon($this->cookie->owner($request), $this->input->body($request, CouponCode::class)));
    }
}
