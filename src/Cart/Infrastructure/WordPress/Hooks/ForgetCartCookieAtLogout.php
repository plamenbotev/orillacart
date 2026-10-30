<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Infrastructure\WordPress\Hooks;

use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Shared\Http\SiteCookies;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Logging out takes the cart cookie with it: the cart stays with the account, and the next person at the same
 * browser starts with none. It is expired everywhere a build ever set it (SiteCookies::everywhere()), so a cookie
 * left at the whole host or the network's domain does not bring the cart back.
 */
#[AsHook('wp_logout', method: 'forget')]
final readonly class ForgetCartCookieAtLogout
{
    public function forget(): void
    {
        if (!headers_sent()) {
            foreach (SiteCookies::everywhere() as $place) {
                setcookie(CartOwner::COOKIE, '', ['expires' => time() - 3600, 'path' => $place['path'], 'domain' => $place['domain'], 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
            }
        }
        unset($_COOKIE[CartOwner::COOKIE]);
    }
}
