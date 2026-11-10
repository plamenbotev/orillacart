<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Http;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CheckoutContact;
use OrillaCart\Shared\Domain\EmailAddress;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\RateLimiter;
use OrillaCart\Shared\Lifecycle\Languages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The checkout page tells of the customer at checkout with their cart: the email so far, and whether they ask to
 * be reminded of the cart if they leave it. So many carts an address may be reminded of a day: an address
 * typed in by someone else is not sent reminder after reminder; past that, the cart keeps the email and no reminder.
 */
#[Route('/cart/checkout-contact', methods: ['POST'], capability: Route::PUBLIC, guard: 'checkout_contact')]
final readonly class CheckoutContactAction implements RequestHandlerInterface
{
    public function __construct(
        private CartService $carts,
        private CartCookie $cookie,
        private RequestMapper $input,
        private JsonResponder $responder,
        private Languages $languages,
        private RateLimiter $limits,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $contact = $this->input->body($request, CheckoutContact::class);
        $email = strtolower(trim($contact->email ?? ''));
        if ($contact->remind && $email !== '' && $this->limits->hit('reminder_address', 'email:' . hash('sha256', EmailAddress::key($email))) !== null) {
            $contact = new CheckoutContact($contact->email, false);
        }
        $token = $this->carts->atCheckout($this->cookie->owner($request), $contact, $this->languages->current());

        return $this->cookie->give($this->responder->noContent(), $token, $request);
    }
}
