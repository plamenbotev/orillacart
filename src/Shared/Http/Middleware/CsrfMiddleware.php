<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Middleware;

use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Security\CsrfProtection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Requires a valid nonce for the route's nonce action on every request that changes something.
 * The nonce comes in the X-WP-Nonce header (REST) or the _wpnonce field (forms).
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-WP-Nonce';
    public const string FIELD = '_wpnonce';

    public function __construct(private CsrfProtection $csrf) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = RouteDefinition::fromRequest($request);

        if (!$route->isSafe($request->getMethod()) && $this->csrf->isRequired()) {
            $token = $this->token($request);
            if ($token === '' || !$this->csrf->isTokenValid($token, $route->nonce)) {
                throw HttpException::invalidNonce();
            }
        }

        return $handler->handle($request);
    }

    private function token(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine(self::HEADER);
        if ($header !== '') {
            return $header;
        }

        $body = $request->getParsedBody();
        $field = \is_array($body) ? ($body[self::FIELD] ?? null) : null;

        return \is_string($field) ? $field : '';
    }
}
