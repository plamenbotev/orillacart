<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Http;

use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\CsrfProtection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A visitor's nonce for the storefront script: visitors' pages carry none, since a page cache would serve one
 * that has expired. Never cached. Logged-in users' pages carry their own: the REST API ignores login cookies
 * on a request without a nonce, so this route only ever sees visitors. For the same reason another site that
 * reads it gets nothing more than a visitor's nonce, and the cart cookie (SameSite=Lax) is not sent to it.
 */
#[Route('/session', methods: ['GET'], capability: Route::PUBLIC)]
final readonly class ShowSessionAction implements RequestHandlerInterface
{
    public function __construct(
        private CsrfProtection $csrf,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok(['nonce' => $this->csrf->newToken(Route::REST_NONCE)])
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
