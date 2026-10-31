<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Application\StorefrontSettingsService;
use OrillaCart\Storefront\Domain\WishlistPolicy;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;

/**
 * The shopper's wishlist on any page, as their account shows it: a customer's from their account, a visitor's
 * from their browser, or, where only customers keep one, a way to log in. The page is the same for everyone (page
 * caches may keep it): the storefront script fills the list in. Nothing while the shop keeps no wishlists.
 */
final readonly class WishlistBlock implements BlockDefinition
{
    public function __construct(
        private StorefrontSettingsService $storefront,
        private ShopLinks $links,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/wishlist';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $policy = $this->storefront->current()->wishlist;
        if ($policy === WishlistPolicy::Off) {
            return '';
        }

        return $this->templates->render('storefront/_wishlist.html.twig', [
            'columns' => max(1, min(6, \is_int($attributes['columns'] ?? null) ? $attributes['columns'] : 4)),
            'show_title' => ($attributes['showTitle'] ?? true) !== false,
            'login_url' => $policy === WishlistPolicy::Accounts ? ($this->links->pages()['account'] ?? wp_login_url()) : null,
        ]);
    }
}
