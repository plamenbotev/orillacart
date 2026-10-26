<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Http;

use OrillaCart\Integrations\Application\OAuthServer;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\ClientAddress;
use OrillaCart\Shared\Http\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Dynamic client registration (RFC 7591) for AI assistants' apps that have no client ID metadata document. Anyone
 * may register, so each address may register so many an hour; a registered client still needs the owner's yes.
 */
#[Route('/oauth/register', methods: ['POST'], capability: Route::PUBLIC, signed: true)]
final readonly class RegisterOAuthClientAction implements RequestHandlerInterface
{
    public const string LIMIT = 'oauth_register';

    public function __construct(
        private OAuthServer $server,
        private RateLimiter $limits,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->server->available()) {
            return $this->responder->exact(['error' => 'temporarily_unavailable', 'error_description' => 'The site offers no OAuth: turn on pretty permalinks, and https.'], 503);
        }
        if ($this->limits->hit(self::LIMIT, 'ip:' . ClientAddress::of($request->getServerParams())) !== null) {
            return $this->responder->exact(['error' => 'slow_down', 'error_description' => 'Too many registrations from this address; wait an hour.'], 429);
        }
        $body = $request->getParsedBody();
        [$status, $answer] = $this->server->registrationAnswer(\is_array($body) ? $body : []);

        return $this->responder->exact($answer, $status)->withHeader('Cache-Control', 'no-store');
    }
}
