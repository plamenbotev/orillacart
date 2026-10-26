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
 * The OAuth token endpoint: an authorization code and its PKCE verifier for tokens, or a refresh token for new
 * ones. Public clients prove themselves with PKCE, so no nonce: the action checks (signed). Errors are RFC 6749's.
 */
#[Route('/oauth/token', methods: ['POST'], capability: Route::PUBLIC, signed: true)]
final readonly class IssueOAuthTokenAction implements RequestHandlerInterface
{
    public const string LIMIT = 'oauth_token';

    public function __construct(
        private OAuthServer $server,
        private RateLimiter $limits,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->limits->hit(self::LIMIT, 'ip:' . ClientAddress::of($request->getServerParams())) !== null) {
            return self::fresh($this->responder->exact(['error' => 'slow_down', 'error_description' => 'Too many token requests; wait a little.'], 429));
        }
        $form = $request->getParsedBody();
        [$status, $answer] = $this->server->tokenAnswer(\is_array($form) ? $form : []);

        return self::fresh($this->responder->exact($answer, $status));
    }

    /** Tokens are never kept by a cache on the way. */
    private static function fresh(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
}
