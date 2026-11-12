<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Checkout\Application\CheckoutService;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Application\EmailConfirmations;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\ClientAddress;
use OrillaCart\Shared\Http\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Places the order. A guest ordering by transfer from an address where as many orders wait unpaid as one shopper
 * may is refused with `orillacart_confirm_email` and emailed a link (at most 5 a day from each address, 3 a day to
 * one email). Followed on any device and confirmed there (`POST /checkout/email-confirmation`), it lets the
 * browser that asked place the order again, counted by that mailbox.
 */
#[Route('/checkout', methods: ['POST'], capability: Route::PUBLIC, guard: 'checkout')]
final readonly class PlaceOrderAction implements RequestHandlerInterface
{
    public const string LINKS = 'email_confirmations';
    public const string LINKS_TO = 'email_confirmation_address';

    public function __construct(
        private CheckoutService $checkout,
        private CartCookie $cookie,
        private RequestMapper $input,
        private JsonResponder $responder,
        private RateLimiter $limits,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $caller = 'ip:' . ClientAddress::of($request->getServerParams());
        // The caller's own first: one out of links uses up none of an address's.
        $mayMail = fn(string $email): bool => $this->limits->hit(self::LINKS, $caller) === null
            && $this->limits->hit(self::LINKS_TO, EmailConfirmations::shopper($email)) === null;

        return $this->responder->ok($this->checkout->placeOrder($this->cookie->owner($request), $this->input->body($request, PlaceOrder::class), $caller, $mayMail), 201);
    }
}
