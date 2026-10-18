<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Http;

use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Payment\Application\PaymentOutcomes;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\QueryStrings;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Providers' notifications (webhooks). They carry no nonce: the gateway checks with the provider that it sent
 * them before anything is read.
 */
#[Route('/payments/{gateway}/webhook', methods: ['POST'], capability: Route::PUBLIC, signed: true, requirements: ['gateway' => '[a-z][a-z0-9_]{0,39}'])]
final readonly class PaymentNotificationAction implements RequestHandlerInterface
{
    public function __construct(
        private PaymentOutcomes $outcomes,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[strtolower((string) $name)] = $request->getHeaderLine((string) $name);
        }
        $this->outcomes->notified(PathParameter::string($request, 'gateway'), new Notification($headers, (string) $request->getBody(), QueryStrings::of($request)));

        return $this->responder->ok(['received' => true]);
    }
}
