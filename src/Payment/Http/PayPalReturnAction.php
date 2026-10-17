<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Http;

use OrillaCart\Payment\Application\PayPal\PayPalPayments;
use OrillaCart\Shared\Http\Output\RedirectResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PayPal sends the customer here after they approve the payment, with the PayPal order id as "token".
 */
#[Route('/payments/paypal/return', methods: ['GET'], capability: Route::PUBLIC)]
final readonly class PayPalReturnAction implements RequestHandlerInterface
{
    public function __construct(
        private PayPalPayments $payments,
        private RedirectResponder $redirect,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $token = $request->getQueryParams()['token'] ?? '';
        $token = \is_string($token) ? $token : '';

        return $this->redirect->to($this->payments->approve($token));
    }
}
