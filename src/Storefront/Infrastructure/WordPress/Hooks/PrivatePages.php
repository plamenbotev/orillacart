<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;

/**
 * The cart, checkout and account pages show one shopper's things: browsers and page caches must not keep them.
 */
#[AsHook('template_redirect')]
final readonly class PrivatePages
{
    public function __construct(private ShopLinks $links) {}

    public function __invoke(): void
    {
        foreach (['cart', 'checkout', 'account'] as $part) {
            if ($this->links->isPage($part)) {
                nocache_headers();
                // Read by caching plugins such as WP Super Cache and W3 Total Cache.
                if (!\defined('DONOTCACHEPAGE')) {
                    \define('DONOTCACHEPAGE', true);
                }

                return;
            }
        }
    }
}
