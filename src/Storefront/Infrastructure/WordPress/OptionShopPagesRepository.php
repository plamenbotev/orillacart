<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Storefront\Domain\ShopPages;
use OrillaCart\Storefront\Domain\ShopPagesRepository;

/**
 * Keeps the shop's page ids in an option. The first activation makes the four pages, each holding its block;
 * later ones make a page again only when the chosen one was deleted, so a part set to "none" stays off.
 * Uninstalling deletes the pages the plugin made.
 */
final readonly class OptionShopPagesRepository implements ShopPagesRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_shop_pages';
    /** The ids of the pages the plugin made, which uninstalling removes. */
    public const string MADE_OPTION = 'orillacart_made_pages';

    public function load(): ShopPages
    {
        $stored = get_option(self::OPTION);
        $id = static fn(string $part): ?int => \is_array($stored) && \is_int($stored[$part] ?? null) && $stored[$part] > 0 ? $stored[$part] : null;

        return new ShopPages($id('shop'), $id('cart'), $id('checkout'), $id('account'));
    }

    public function save(ShopPages $pages): void
    {
        update_option(self::OPTION, ['shop' => $pages->shop, 'cart' => $pages->cart, 'checkout' => $pages->checkout, 'account' => $pages->account], true);
    }

    public function isPage(int $id): bool
    {
        return get_post_type($id) === 'page' && get_post_status($id) === 'publish';
    }

    public function activate(): void
    {
        $first = get_option(self::OPTION) === false;
        $pages = $this->load();
        $page = fn(?int $id, string $title, string $block): ?int => match (true) {
            $id !== null && $this->isPage($id), $id === null && !$first => $id,
            default => $this->make($title, $block),
        };

        $this->save(new ShopPages(
            $page($pages->shop, __('Shop', 'orillacart'), 'products'),
            $page($pages->cart, __('Cart', 'orillacart'), 'cart'),
            $page($pages->checkout, __('Checkout', 'orillacart'), 'checkout'),
            $page($pages->account, __('My account', 'orillacart'), 'account'),
        ));
    }

    public function upgrade(?string $fromVersion): void
    {
        $this->activate();
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        $made = get_option(self::MADE_OPTION);
        foreach (\is_array($made) ? $made : [] as $id) {
            if (\is_int($id) && get_post_type($id) === 'page') {
                wp_delete_post($id, true);
            }
        }
        delete_option(self::MADE_OPTION);
        delete_option(self::OPTION);
    }

    private function make(string $title, string $block): int
    {
        $id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => $title,
            // Wide where the theme allows it: grids, tables and the two-column checkout need the room.
            'post_content' => \sprintf('<!-- wp:orillacart/%s {"align":"wide"} /-->', $block),
            'comment_status' => 'closed',
        ], true);
        if ($id instanceof \WP_Error) {
            throw new \RuntimeException('The shop page could not be made: ' . $id->get_error_message());
        }
        $made = get_option(self::MADE_OPTION);
        update_option(self::MADE_OPTION, [...array_values(array_filter(\is_array($made) ? $made : [], \is_int(...))), $id], false);

        return $id;
    }
}
