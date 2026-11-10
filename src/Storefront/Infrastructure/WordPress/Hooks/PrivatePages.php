<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\ReviewLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontAccountLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontOrderLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontWithdrawalLinks;

/**
 * The cart, checkout and account pages show one shopper's things: browsers and page caches must not keep them. A
 * receipt, the page an account is made on, and a product's page opened from a receipt to review it, have a secret
 * in their address too: never stored at all, and the address never sent on to the sites their links go to. A
 * shopper who chose another currency than the shop's sees every page priced in it: those are theirs too. Any
 * other page with the Cart or Checkout block is the shopper's as well: the page's content says so before it
 * renders, and the blocks themselves as they render (markPrivate()), for those a template puts on it. A page's
 * headers go out with its first bytes, which in a classic theme are sent before its content, sidebars and footer
 * render: the page is held back until it is whole, so a block anywhere on it can still have the headers say so.
 */
#[AsHook('template_redirect')]
#[AsHook('template_include', method: 'hold', filter: true, priority: \PHP_INT_MAX)]
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
        if (isset($_GET[StorefrontOrderLinks::RECEIPT_QUERY]) || isset($_GET[StorefrontAccountLinks::CONFIRM_QUERY]) || isset($_GET[ReviewLinks::QUERY]) || isset($_GET[StorefrontWithdrawalLinks::QUERY])) {
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
     * Holds the page's output from the moment its template is chosen until WordPress sends it whole as the
     * request ends. Feeds, robots.txt and files a plugin sends are not templates, and are not held; nor is
     * anything when the filter is asked outside WordPress's template loader, which runs template_redirect first.
     * A page known to be the shopper's already has its headers.
     */
    public function hold(mixed $template): mixed
    {
        if (!self::isPrivate() && did_action('template_redirect') > 0) {
            ob_start();
        }

        return $template;
    }

    /**
     * A block that shows the shopper's own things marks the page it is on: the headers say so while the page is
     * held back (hold()) or a block theme renders it before sending it, and caching plugins read DONOTCACHEPAGE
     * at the end of the page, which is all there is left to say should something have sent the page's top already.
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
