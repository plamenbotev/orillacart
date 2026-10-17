<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Http;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Shared\Http\Security\Authorization;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The browser's cart cookie: who owns a cart, and handing out a new token. The cookie is HttpOnly, so scripts
 * cannot read it, and SameSite=Lax, so other sites cannot make the browser change the cart or order from it.
 */
final readonly class CartCookie
{
    public const string NAME = 'orillacart_cart';

    public function __construct(private Authorization $authorization) {}

    public function owner(ServerRequestInterface $request): CartOwner
    {
        $token = $request->getCookieParams()[self::NAME] ?? null;

        return new CartOwner(\is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $token : null, $this->authorization->userId());
    }

    public function give(ResponseInterface $response, ?string $token, ServerRequestInterface $request): ResponseInterface
    {
        if ($token === null) {
            return $response;
        }
        $secure = $request->getUri()->getScheme() === 'https' ? '; Secure' : '';
        $days = new \DateInterval(CartService::KEEP_FOR)->d;

        return $response->withAddedHeader('Set-Cookie', \sprintf('%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax%s', self::NAME, $token, $days * 86400, $secure));
    }
}
