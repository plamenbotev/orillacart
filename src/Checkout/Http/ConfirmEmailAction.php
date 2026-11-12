<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Checkout\Application\Dto\ConfirmEmail;
use OrillaCart\Checkout\Application\EmailConfirmations;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Confirms a guest's email for ordering, with the token of the link sent to it (`orillacart_confirm_email` of
 * `POST /checkout`): from whatever device the mail is read on. The browser that asked then places its order.
 */
#[Route('/checkout/email-confirmation', methods: ['POST'], capability: Route::PUBLIC, guard: 'email_confirmed')]
final readonly class ConfirmEmailAction implements RequestHandlerInterface
{
    public function __construct(
        private EmailConfirmations $confirmations,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->confirmations->confirm($this->input->body($request, ConfirmEmail::class)->token);

        return $this->responder->ok(['message' => 'Your email is confirmed. Go back to where you filled in your order and place it.']);
    }
}
