<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Http;

use OrillaCart\Integrations\Application\OAuthServer;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The OAuth server's metadata (RFC 8414), where a client looks for it by appending a well-known path to the
 * issuer; the site's root well-known addresses answer the same (OAuthWellKnown).
 */
#[Route('/oauth/.well-known/oauth-authorization-server', methods: ['GET'], capability: Route::PUBLIC)]
#[Route('/oauth/.well-known/openid-configuration', methods: ['GET'], capability: Route::PUBLIC)]
final readonly class ShowAuthorizationServerAction implements RequestHandlerInterface
{
    public function __construct(
        private OAuthServer $server,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->server->available()) {
            throw HttpException::notFound();
        }

        return $this->responder->exact($this->server->metadata())->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
