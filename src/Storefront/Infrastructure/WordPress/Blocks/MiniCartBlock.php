<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * A link to the cart with the number of items, for a header. Nothing shows without a cart page.
 */
final readonly class MiniCartBlock implements BlockDefinition
{
    public function __construct(
        private CartService $carts,
        private Shopper $shopper,
        private ShopLinks $links,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/mini-cart';
    }

    public static function metadata(): array
    {
        return [
            'title' => __('Mini cart', 'orillacart'),
            'description' => __('A link to the cart with the number of items in it.', 'orillacart'),
            'icon' => 'cart',
        ];
    }

    public function render(array $attributes): string
    {
        $url = $this->links->pages()['cart'];
        if ($url === null) {
            return '';
        }

        return $this->templates->render('storefront/mini-cart.html.twig', [
            'cart' => $this->carts->cart($this->shopper->cart())->cart,
            'cart_url' => $url,
        ]);
    }
}
