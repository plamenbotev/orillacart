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
use OrillaCart\Storefront\Infrastructure\WordPress\AddressChoices;
use OrillaCart\Storefront\Infrastructure\WordPress\Merchandise;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductCards;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * The cart page: what is in the cart, changing quantities, the products the shop offers with them (cross-sells),
 * and the way to the checkout.
 */
final readonly class CartBlock implements BlockDefinition
{
    public function __construct(
        private CartService $carts,
        private Shopper $shopper,
        private ShopLinks $links,
        private TemplateRenderer $templates,
        private CheckoutService $checkout,
        private AddressChoices $addresses,
        private Merchandise $merchandise,
        private ProductCards $cards,
    ) {}

    public static function name(): string
    {
        return 'orillacart/cart';
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
                // Priced as checkout prices it, without an address yet: what the codes take off, and an estimate of
                // tax and shipping at the customer's saved address or the shop's.
                $priced = $this->checkout->summary($owner, new CheckoutDetails());
            } catch (CheckoutRuleViolation) {
            }
        }

        $productIds = array_map(static fn(CartLineView $line): int => $line->productId, $cart->lines);

        return $this->templates->render('storefront/cart.html.twig', [
            'cart' => $cart,
            'cross_sells' => $this->cards->cards($this->merchandise->crossSells($productIds)),
            'coupons' => $priced->coupons ?? [],
            'discount' => $priced->discount ?? '0',
            'estimate' => $priced?->estimatedFor === null ? null : $priced,
            'estimated_for' => $priced?->estimatedFor === null ? null : $this->addresses->countryName($priced->estimatedFor),
            'product_urls' => $this->links->products($productIds),
            'links' => $links,
        ]);
    }
}
