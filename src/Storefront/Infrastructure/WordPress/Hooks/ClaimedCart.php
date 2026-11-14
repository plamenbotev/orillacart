<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Shared\Http\SiteCookies;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * A customer who logged in with a visitor's cart in the browser: on the first page they open, the cart becomes
 * theirs (CartService::claim()), and the browser gets its new token. Reading a cart changes nothing, so this is
 * where it happens; after the reminder's and the agent's links (priority 0), which put a cart in the browser.
 */
#[AsHook('template_redirect', priority: 2)]
final readonly class ClaimedCart
{
    public function __construct(
        private CartService $carts,
        private Shopper $shopper,
    ) {}

    public function __invoke(): void
    {
        $owner = $this->shopper->cart();
        if ($owner->customerId === null || $owner->token === null) {
            return;
        }
        $token = $this->carts->claim($owner);
        if ($token === null) {
            return;
        }
        $days = new \DateInterval(CartService::KEEP_FOR)->d;
        setcookie(CartOwner::COOKIE, $token, ['expires' => time() + $days * DAY_IN_SECONDS, 'path' => SiteCookies::path(), 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[CartOwner::COOKIE] = $token;
    }
}
