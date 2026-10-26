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

/** The MCP endpoint's protected resource metadata (RFC 9728): which OAuth server gives its tokens. */
#[Route('/oauth/protected-resource', methods: ['GET'], capability: Route::PUBLIC)]
final readonly class ShowProtectedResourceAction implements RequestHandlerInterface
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

        return $this->responder->exact($this->server->protectedResource())->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
