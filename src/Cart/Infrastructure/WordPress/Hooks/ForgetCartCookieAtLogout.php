<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Infrastructure\WordPress\Hooks;

use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Shared\Http\SiteCookies;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Logging out takes the cart cookie with it: the cart stays with the account, and the next person at the same
 * browser starts with none.
 */
#[AsHook('wp_logout', method: 'forget')]
final readonly class ForgetCartCookieAtLogout
{
    public function forget(): void
    {
        if (!headers_sent()) {
            setcookie(CartOwner::COOKIE, '', ['expires' => time() - 3600, 'path' => SiteCookies::path(), 'domain' => SiteCookies::domain(), 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        }
        unset($_COOKIE[CartOwner::COOKIE]);
    }
}
