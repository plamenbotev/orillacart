<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\RenderedFreeShipping;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * How much more the shopper's cart needs to ship free, with a bar, or that it ships free: for a header, the cart,
 * the mini cart's sidebar or a product page. Nothing while no free rate would take the cart, or it ships nothing.
 *
 * A visitor's page may come from a page cache, so it carries no one's cart: the storefront script fills it in.
 * A logged-in customer's, which caches do not keep, is filled in here.
 */
final readonly class FreeShippingProgressBlock implements BlockDefinition
{
    public function __construct(
        private RenderedFreeShipping $progress,
        private Shopper $shopper,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/free-shipping-progress';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        return $this->templates->render('storefront/free-shipping-progress.html.twig', [
            'html' => $this->shopper->customerId() === null ? '' : $this->progress->render($this->shopper->cart()),
            'show_bar' => ($attributes['showBar'] ?? true) !== false,
        ]);
    }
}
