<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\ReviewLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontAccountLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontOrderLinks;

/**
 * The cart, checkout and account pages show one shopper's things: browsers and page caches must not keep them. A
 * receipt, the page an account is made on, and a product's page opened from a receipt to review it, have a secret
 * in their address too: never stored at all, and the address never sent on to the sites their links go to. A
 * shopper who chose another currency than the shop's sees every page priced in it: those are theirs too. Any
 * other page with the Cart or Checkout block is the shopper's as well: the page's content says so before it
 * renders, and the blocks themselves as they render (markPrivate()), for those a template puts on it.
 */
#[AsHook('template_redirect')]
final readonly class PrivatePages
{
    /** The blocks that show the shopper's cart, wherever they are. */
    private const array SHOPPERS_BLOCKS = ['orillacart/cart', 'orillacart/checkout'];

    public function __construct(
        private ShopLinks $links,
        private Currencies $currencies,
    ) {}

    public function __invoke(): void
    {
        if (isset($_GET[StorefrontOrderLinks::RECEIPT_QUERY]) || isset($_GET[StorefrontAccountLinks::CONFIRM_QUERY]) || isset($_GET[ReviewLinks::QUERY])) {
            self::private();
            header('Cache-Control: no-store, private');
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow');

            return;
        }
        if ($this->currencies->current() !== $this->currencies->codes()[0]) {
            self::private();

            return;
        }
        foreach (['cart', 'checkout', 'account'] as $part) {
            if ($this->links->isPage($part)) {
                self::private();

                return;
            }
        }
        $post = is_singular() ? get_queried_object() : null;
        if ($post instanceof \WP_Post) {
            foreach (self::SHOPPERS_BLOCKS as $block) {
                if (has_block($block, $post)) {
                    self::private();

                    return;
                }
            }
        }
    }

    /**
     * A block that shows the shopper's own things marks the page it is on: caching plugins read DONOTCACHEPAGE
     * at the end of the page, and the headers go out still where a block theme renders the page before sending it.
     */
    public static function markPrivate(): void
    {
        self::private();
    }

    /** Whether this response is kept from page caches: what it shows is the shopper's alone. */
    public static function isPrivate(): bool
    {
        return \defined('DONOTCACHEPAGE') && DONOTCACHEPAGE;
    }

    private static function private(): void
    {
        if (!headers_sent()) {
            nocache_headers();
        }
        // Read by caching plugins such as WP Super Cache and W3 Total Cache.
        if (!\defined('DONOTCACHEPAGE')) {
            \define('DONOTCACHEPAGE', true);
        }
    }
}
