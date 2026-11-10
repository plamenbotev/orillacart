<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Http;

use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Payment\Application\PaymentOutcomes;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\QueryStrings;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\ClientAddress;
use OrillaCart\Shared\Http\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Providers' notifications (webhooks). They carry no nonce: the gateway checks that the provider sent them before
 * anything is read. Counted per caller, so a flood from one address is turned away before any gateway works.
 */
#[Route('/payments/{gateway}/webhook', methods: ['POST'], capability: Route::PUBLIC, signed: true, requirements: ['gateway' => '[a-z][a-z0-9_]{0,39}'])]
final readonly class PaymentNotificationAction implements RequestHandlerInterface
{
    public function __construct(
        private PaymentOutcomes $outcomes,
        private JsonResponder $responder,
        private RateLimiter $limits,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $wait = $this->limits->hit('payment_notifications', 'ip:' . ClientAddress::of($request->getServerParams()));
        if ($wait !== null) {
            throw new HttpException(429, 'orillacart_too_many_requests', 'That was a lot at once. Wait a little, then try again.', ['retry_after' => $wait]);
        }
        $headers = [];
        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[strtolower((string) $name)] = $request->getHeaderLine((string) $name);
        }
        try {
            $this->outcomes->notified(PathParameter::string($request, 'gateway'), new Notification($headers, (string) $request->getBody(), QueryStrings::of($request)));
        } catch (RuleViolation $e) {
            return match ($e->errorCode) {
                // The provider sends it again: say when, and that it is not the notification's fault.
                'orillacart_payment_provider_unavailable' => $this->responder->error(503, $e->errorCode, $e->getMessage())->withHeader('Retry-After', '60'),
                'orillacart_invalid_notification' => $this->responder->error(400, $e->errorCode, $e->getMessage()),
                default => throw $e,
            };
        }

        return $this->responder->ok(['received' => true]);
    }
}
