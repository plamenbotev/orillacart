<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Http;

use OrillaCart\Payment\Application\Dto\PayPalNotification;
use OrillaCart\Payment\Application\PayPal\PayPalPayments;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PayPal's webhook. It carries no nonce: PayPal signs it, and the signature is checked with PayPal before
 * anything is read.
 */
#[Route('/payments/paypal/webhook', methods: ['POST'], capability: Route::PUBLIC, signed: true)]
final readonly class PayPalWebhookAction implements RequestHandlerInterface
{
    private const array HEADERS = ['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'];

    public function __construct(
        private PayPalPayments $payments,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach (self::HEADERS as $name) {
            $headers[$name] = $request->getHeaderLine($name);
        }
        $this->payments->notify(new PayPalNotification($headers, (string) $request->getBody()));

        return $this->responder->ok(['received' => true]);
    }
}
