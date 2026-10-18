<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Infrastructure\WordPress\Hooks;

use OrillaCart\Cart\Http\CartCookie;
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
            setcookie(CartCookie::NAME, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        }
        unset($_COOKIE[CartCookie::NAME]);
    }
}
