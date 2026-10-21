<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\ReviewLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontAccountLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontOrderLinks;

/**
 * The cart, checkout and account pages show one shopper's things: browsers and page caches must not keep them. A
 * receipt, the page an account is made on, and a product's page opened from a receipt to review it, have a secret
 * in their address too: never stored at all, and the address never sent on to the sites their links go to.
 */
#[AsHook('template_redirect')]
final readonly class PrivatePages
{
    public function __construct(private ShopLinks $links) {}

    public function __invoke(): void
    {
        if (isset($_GET[StorefrontOrderLinks::RECEIPT_QUERY]) || isset($_GET[StorefrontAccountLinks::CONFIRM_QUERY]) || isset($_GET[ReviewLinks::QUERY])) {
            self::private();
            header('Cache-Control: no-store, private');
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow');

            return;
        }
        foreach (['cart', 'checkout', 'account'] as $part) {
            if ($this->links->isPage($part)) {
                self::private();

                return;
            }
        }
    }

    private static function private(): void
    {
        nocache_headers();
        // Read by caching plugins such as WP Super Cache and W3 Total Cache.
        if (!\defined('DONOTCACHEPAGE')) {
            \define('DONOTCACHEPAGE', true);
        }
    }
}
