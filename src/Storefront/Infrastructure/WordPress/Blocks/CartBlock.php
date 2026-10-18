<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartLineView;
use OrillaCart\Checkout\Application\CheckoutService;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
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
        private CheckoutService $checkout,
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
        $links = $this->links->pages();
        if ($links['cart'] === null) {
            return $this->templates->render('storefront/catalog-only.html.twig', ['links' => $links]);
        }
        $owner = $this->shopper->cart();
        $cart = $this->carts->cart($owner)->cart;
        $priced = null;
        if ($cart->lines !== [] && $cart->orderable) {
            try {
                // Priced as checkout prices it, without an address yet, for what the codes take off.
                $priced = $this->checkout->summary($owner, new CheckoutDetails());
            } catch (CheckoutRuleViolation) {
            }
        }

        return $this->templates->render('storefront/cart.html.twig', [
            'cart' => $cart,
            'coupons' => $priced->coupons ?? [],
            'discount' => $priced->discount ?? '0',
            'product_urls' => $this->links->products(array_map(static fn(CartLineView $line): int => $line->productId, $cart->lines)),
            'links' => $links,
        ]);
    }
}
