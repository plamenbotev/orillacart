<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Http;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Application\Dto\CartResult;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Http\SiteCookies;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The browser's cart cookie: who owns a cart, and handing out a new token. The cookie is HttpOnly, so scripts
 * cannot read it, and SameSite=Lax, so other sites cannot make the browser change the cart or order from it;
 * Secure on a site served over HTTPS. Every change to the cart gives it again, so a cart in use does not expire
 * in the browser before the shop forgets it. It is set at the site's own cookie path (SiteCookies), so two
 * shops of a network in folders of one domain each keep a cart of their own.
 */
final readonly class CartCookie
{
    public const string NAME = CartOwner::COOKIE;

    public function __construct(private Authorization $authorization) {}

    public function owner(ServerRequestInterface $request): CartOwner
    {
        return CartOwner::fromCookie($request->getCookieParams()[self::NAME] ?? null, $this->authorization->userId());
    }

    /** After a change: the new token, or again the one the browser sent, for as long as the shop keeps carts. */
    public function keep(ResponseInterface $response, CartResult $result, ServerRequestInterface $request): ResponseInterface
    {
        return $this->give($response, $result->newToken ?? $this->owner($request)->token, $request);
    }

    public function give(ResponseInterface $response, ?string $token, ServerRequestInterface $request): ResponseInterface
    {
        if ($token === null) {
            return $response;
        }
        $secure = $request->getUri()->getScheme() === 'https' ? '; Secure' : '';
        $domain = SiteCookies::domain() === '' ? '' : '; Domain=' . SiteCookies::domain();
        $days = new \DateInterval(CartService::KEEP_FOR)->d;

        return $response->withAddedHeader('Set-Cookie', \sprintf('%s=%s; Path=%s%s; Max-Age=%d; HttpOnly; SameSite=Lax%s', self::NAME, $token, SiteCookies::path(), $domain, $days * 86400, $secure));
    }
}
