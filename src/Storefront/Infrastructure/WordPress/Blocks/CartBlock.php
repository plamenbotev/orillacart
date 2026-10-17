<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartLineView;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * The cart page: what is in the cart, changing quantities, and the way to the checkout.
 */
final readonly class CartBlock implements BlockDefinition
{
    public function __construct(
        private CartService $carts,
        private Shopper $shopper,
        private ShopLinks $links,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/cart';
    }

    public static function metadata(): array
    {
        return [
            'title' => __('Cart', 'orillacart'),
            'description' => __('What the shopper put in the cart, with quantities and the way to the checkout.', 'orillacart'),
            'icon' => 'cart',
        ];
    }

    public function render(array $attributes): string
    {
        $cart = $this->carts->cart($this->shopper->cart())->cart;

        return $this->templates->render('storefront/cart.html.twig', [
            'cart' => $cart,
            'product_urls' => $this->links->products(array_map(static fn(CartLineView $line): int => $line->productId, $cart->lines)),
            'links' => $this->links->pages(),
        ]);
    }
}
