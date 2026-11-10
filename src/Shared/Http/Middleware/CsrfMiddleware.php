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
 * A logged-in user's nonce comes in the X-WP-Nonce header (REST) or the _wpnonce field (forms): WordPress checks
 * it too, and takes a request without it for a visitor's. A visitor's comes in the shop's own header: theirs is
 * made for this site (WordPressCsrfProtection), and WordPress would refuse it in its own header before the route
 * runs. Signed routes, which payment
 * providers call, verify the provider's signature in their action instead. A browser's request that carries its
 * cookies and says it comes from another site (Origin) is refused before the nonce is looked at, and so is its
 * asking for the visitor's session (which hands out the nonce): a visitor's nonce is the same for every visitor,
 * so it alone does not tell this site's pages from a sibling site's on the same network.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-WP-Nonce';

    /** A visitor's nonce, which WordPress does not read: GET /session gives it. */
    public const string VISITOR_HEADER = 'X-OrillaCart-Nonce';
    public const string FIELD = '_wpnonce';

    /**
     * A request attribute for a request the shop built itself to run a route for a call that was checked already
     * (an AI assistant's tool: the MCP endpoint checked its own request, WordPress checks an ability's). Clients
     * cannot set attributes, so this cannot come from outside.
     */
    public const string CHECKED = '_orillacart_csrf_checked';

    public function __construct(private CsrfProtection $csrf) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = RouteDefinition::fromRequest($request);
        $origin = $request->getHeaderLine('Origin');
        $unsafe = !\in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($origin !== '' && ($unsafe || $route->path === '/session') && $request->getAttribute(self::CHECKED) !== true && $this->csrf->isRequired() && !$this->csrf->allowsOrigin($origin)) {
            throw new HttpException(403, 'orillacart_foreign_origin', 'This request came from another site.');
        }

        if ($route->needsNonce($request->getMethod()) && $request->getAttribute(self::CHECKED) !== true && $this->csrf->isRequired()) {
            $token = $this->token($request);
            if ($token === '' || !$this->csrf->isTokenValid($token, $route->nonce)) {
                throw HttpException::invalidNonce();
            }
        }

        return $handler->handle($request);
    }

    private function token(ServerRequestInterface $request): string
    {
        foreach ([self::HEADER, self::VISITOR_HEADER] as $name) {
            $header = $request->getHeaderLine($name);
            if ($header !== '') {
                return $header;
            }
        }

        $body = $request->getParsedBody();
        $field = \is_array($body) ? ($body[self::FIELD] ?? null) : null;

        return \is_string($field) ? $field : '';
    }
}
