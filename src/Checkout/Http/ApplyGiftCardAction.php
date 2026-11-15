<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Checkout\Application\CheckoutCodes;
use OrillaCart\GiftCards\Application\Dto\GiftCardCodeInput;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/cart/gift-cards', methods: ['POST'], capability: Route::PUBLIC, guard: 'gift_cards')]
final readonly class ApplyGiftCardAction implements RequestHandlerInterface
{
    public function __construct(
        private CheckoutCodes $codes,
        private CartCookie $cookie,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$owner, $token] = $this->cookie->claimed($request);

        return $this->cookie->give($this->responder->ok($this->codes->applyGiftCard($owner, $this->input->body($request, GiftCardCodeInput::class))), $token, $request);
    }
}
